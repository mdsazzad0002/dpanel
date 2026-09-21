use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;
use std::{process::Command, sync::Arc, time::Duration};

/// The panel's chat channels (Telegram, WhatsApp, website widget, ...) hand
/// us a URL to a voice note or an image; we never accept raw bytes over this
/// API because every caller already has the file behind a URL from its own
/// platform. Both handlers download once, then hand off to a blocking
/// worker thread since whisper.cpp/tesseract are synchronous CPU-bound
/// libraries with no async API.
const MAX_MEDIA_BYTES: usize = 25 * 1024 * 1024;
const DEFAULT_WHISPER_MODEL_PATH: &str = "/var/lib/drust/models/ggml-base-q5_1.bin";

fn whisper_model_path() -> String {
    std::env::var("DRUST_WHISPER_MODEL").unwrap_or_else(|_| DEFAULT_WHISPER_MODEL_PATH.into())
}

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/media/transcribe", post(handle_transcribe))
        .route("/api/v1/media/ocr", post(handle_ocr))
}

#[derive(Deserialize)]
struct MediaRequest {
    url: String,
    /// ISO 639-1 language hint for transcription (e.g. "bn", "en"). Omitted
    /// or "auto" lets whisper.cpp detect the spoken language itself.
    language: Option<String>,
    /// Extra headers to send with the download request — e.g.
    /// `{"Authorization": "Bearer ..."}`. Several platforms (WhatsApp
    /// Cloud API, Slack) hand back a media URL that 403s without the
    /// same bearer token used to talk to their own API, unlike Telegram/
    /// Facebook/Instagram whose webhook attachment URLs are already
    /// public/pre-signed.
    headers: Option<std::collections::HashMap<String, String>>,
}

async fn handle_transcribe(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<MediaRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let bytes = match download(&req.url, &req.headers).await {
        Ok(bytes) => bytes,
        Err(error) => return ApiResponse::error(&error).into_response(),
    };

    let language = req.language;
    match tokio::task::spawn_blocking(move || transcribe(&bytes, language.as_deref())).await {
        Ok(Ok(text)) => {
            ApiResponse::ok_data("Transcribed", serde_json::json!({ "text": text }))
                .into_response()
        }
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => {
            ApiResponse::error(&format!("transcription worker failed: {error}")).into_response()
        }
    }
}

async fn handle_ocr(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<MediaRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let bytes = match download(&req.url, &req.headers).await {
        Ok(bytes) => bytes,
        Err(error) => return ApiResponse::error(&error).into_response(),
    };

    match tokio::task::spawn_blocking(move || ocr(&bytes)).await {
        Ok(Ok(text)) => {
            ApiResponse::ok_data("Extracted text", serde_json::json!({ "text": text }))
                .into_response()
        }
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("OCR worker failed: {error}")).into_response(),
    }
}

async fn download(
    url: &str,
    extra_headers: &Option<std::collections::HashMap<String, String>>,
) -> Result<Vec<u8>, String> {
    if !(url.starts_with("http://") || url.starts_with("https://")) {
        return Err("url must be http or https".into());
    }

    let client = reqwest::Client::builder()
        .connect_timeout(Duration::from_secs(10))
        .timeout(Duration::from_secs(30))
        .build()
        .map_err(|error| format!("download client failed: {error}"))?;

    let mut request = client
        .get(url)
        .header(reqwest::header::USER_AGENT, "drust-media/1.0");

    for (key, value) in extra_headers.iter().flatten() {
        request = request.header(key, value);
    }

    let response = request
        .send()
        .await
        .map_err(|error| format!("download failed: {error}"))?;

    if !response.status().is_success() {
        return Err(format!("download returned {}", response.status()));
    }

    let bytes = response
        .bytes()
        .await
        .map_err(|error| format!("download failed: {error}"))?;

    if bytes.len() > MAX_MEDIA_BYTES {
        return Err("media exceeds the 25MB size limit".into());
    }

    Ok(bytes.to_vec())
}

/// Decodes whatever container/codec ffmpeg recognizes (ogg/opus voice
/// notes, mp3, m4a, webm, ...) down to 16kHz mono PCM — the exact format
/// whisper.cpp expects — rather than trying to support every audio codec
/// natively in Rust.
fn transcribe(bytes: &[u8], language: Option<&str>) -> Result<String, String> {
    let input = tempfile::Builder::new()
        .suffix(".input")
        .tempfile()
        .map_err(|error| error.to_string())?;
    std::fs::write(input.path(), bytes).map_err(|error| error.to_string())?;

    let wav = tempfile::Builder::new()
        .suffix(".wav")
        .tempfile()
        .map_err(|error| error.to_string())?;

    let status = Command::new("ffmpeg")
        .args([
            "-y",
            "-loglevel",
            "error",
            "-i",
        ])
        .arg(input.path())
        .args(["-ac", "1", "-ar", "16000", "-f", "wav"])
        .arg(wav.path())
        .status()
        .map_err(|error| format!("ffmpeg failed to start: {error}"))?;

    if !status.success() {
        return Err("ffmpeg could not decode this audio file".into());
    }

    let mut reader = hound::WavReader::open(wav.path())
        .map_err(|error| format!("failed to read decoded audio: {error}"))?;
    let samples: Vec<f32> = reader
        .samples::<i16>()
        .map(|sample| sample.map(|value| value as f32 / i16::MAX as f32))
        .collect::<Result<_, _>>()
        .map_err(|error| format!("failed to read decoded audio: {error}"))?;

    if samples.is_empty() {
        return Err("decoded audio was empty".into());
    }

    let ctx = whisper_context()?;
    let mut state = ctx
        .create_state()
        .map_err(|error| format!("failed to init speech model: {error}"))?;

    let mut params =
        whisper_rs::FullParams::new(whisper_rs::SamplingStrategy::Greedy { best_of: 1 });
    params.set_n_threads(num_cpus().min(8));
    params.set_translate(false);
    params.set_print_progress(false);
    params.set_print_special(false);
    params.set_print_realtime(false);
    params.set_print_timestamps(false);
    match language.filter(|value| !value.is_empty() && *value != "auto") {
        Some(lang) => params.set_language(Some(lang)),
        None => params.set_language(Some("auto")),
    }

    state
        .full(params, &samples)
        .map_err(|error| format!("transcription failed: {error}"))?;

    let segments = state
        .full_n_segments()
        .map_err(|error| format!("transcription failed: {error}"))?;
    let mut text = String::new();
    for i in 0..segments {
        if let Ok(segment) = state.full_get_segment_text(i) {
            text.push_str(segment.trim());
            text.push(' ');
        }
    }

    Ok(text.trim().to_string())
}

/// Bengali + English are the two languages the panel's own userbase
/// actually needs; tesseract can be fed more `traineddata` files later if
/// another language becomes necessary.
fn ocr(bytes: &[u8]) -> Result<String, String> {
    let input = tempfile::Builder::new()
        .suffix(".img")
        .tempfile()
        .map_err(|error| error.to_string())?;
    std::fs::write(input.path(), bytes).map_err(|error| error.to_string())?;

    let path = input
        .path()
        .to_str()
        .ok_or("temporary file path was not valid UTF-8")?;

    let mut tesseract = tesseract::Tesseract::new(None, Some("eng+ben"))
        .map_err(|error| format!("failed to init OCR engine: {error}"))?
        .set_image(path)
        .map_err(|error| format!("failed to load image: {error}"))?;

    let text = tesseract
        .get_text()
        .map_err(|error| format!("OCR failed: {error}"))?;

    Ok(text.trim().to_string())
}

/// Loading the model's weights from disk (and whisper.cpp's internal graph
/// setup) costs several seconds — reloading it on every request made every
/// transcription slow regardless of audio length. It's loaded once here and
/// reused for the life of the process; only `create_state()` (cheap) runs
/// per request.
static WHISPER_CONTEXT: std::sync::OnceLock<whisper_rs::WhisperContext> =
    std::sync::OnceLock::new();

fn whisper_context() -> Result<&'static whisper_rs::WhisperContext, String> {
    if let Some(ctx) = WHISPER_CONTEXT.get() {
        return Ok(ctx);
    }

    let ctx = whisper_rs::WhisperContext::new_with_params(
        &whisper_model_path(),
        whisper_rs::WhisperContextParameters::default(),
    )
    .map_err(|error| format!("failed to load speech model: {error}"))?;

    Ok(WHISPER_CONTEXT.get_or_init(|| ctx))
}

fn num_cpus() -> i32 {
    std::thread::available_parallelism()
        .map(|value| value.get() as i32)
        .unwrap_or(4)
}
