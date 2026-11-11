use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::{get, post},
};
use serde::{Deserialize, Serialize};
use std::{sync::Arc, time::Duration};

/// Callers hand us either a URL to a voice note or an image, which we
/// download, or the file itself as base64 `data` (the AI Gateway chat's
/// uploaded images have no public URL). Both handlers then hand off to a
/// blocking worker thread since whisper.cpp/tesseract are synchronous
/// CPU-bound libraries with no async API.
const MAX_MEDIA_BYTES: usize = 25 * 1024 * 1024;
/// The whisper.cpp model files live here (`ggml-*.bin`); the panel's
/// Voice to Text page picks one of them.
fn whisper_models_dir() -> std::path::PathBuf {
    crate::platform::paths().state_dir().join("models")
}

/// What the panel chose on its Voice to Text page: the model file (a name
/// inside `whisper_models_dir()`) and how many CPU threads one run may use.
#[derive(Serialize, Deserialize, Default, Clone)]
struct WhisperSettings {
    #[serde(default)]
    model: Option<String>,
    #[serde(default)]
    threads: Option<i32>,
}

fn whisper_settings_path() -> std::path::PathBuf {
    crate::platform::paths().state_dir().join("whisper.json")
}

fn load_whisper_settings() -> WhisperSettings {
    std::fs::read_to_string(whisper_settings_path())
        .ok()
        .and_then(|raw| serde_json::from_str(&raw).ok())
        .unwrap_or_default()
}

fn save_whisper_settings(settings: &WhisperSettings) -> Result<(), String> {
    let raw = serde_json::to_string_pretty(settings).map_err(|error| error.to_string())?;
    std::fs::write(whisper_settings_path(), raw).map_err(|error| error.to_string())
}

/// A bare `ggml-*.bin` file name in the models directory, nothing else.
fn valid_model_file(name: &str) -> bool {
    !name.contains('/')
        && !name.contains('\\')
        && !name.starts_with('.')
        && name.ends_with(".bin")
        && whisper_models_dir().join(name).is_file()
}

/// The panel's choice first, then DRUST_WHISPER_MODEL, then the small default.
fn whisper_model_path() -> String {
    if let Some(model) = load_whisper_settings()
        .model
        .filter(|m| valid_model_file(m))
    {
        return whisper_models_dir().join(model).display().to_string();
    }
    std::env::var("DRUST_WHISPER_MODEL").unwrap_or_else(|_| {
        whisper_models_dir()
            .join("ggml-base-q5_1.bin")
            .display()
            .to_string()
    })
}

/// Threads for one whisper run: the panel's limit, else every core up to 8.
/// One run with 8 threads shows as ~800% CPU in `top` (100% per core).
fn whisper_threads() -> i32 {
    let cpus = num_cpus();
    load_whisper_settings()
        .threads
        .filter(|t| *t >= 1 && *t <= cpus)
        .unwrap_or_else(|| cpus.min(8))
}

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/media/transcribe", post(handle_transcribe))
        .route("/api/v1/media/ocr", post(handle_ocr))
        .route(
            "/api/v1/media/whisper",
            get(handle_whisper_status).post(handle_whisper_settings),
        )
        .route(
            "/api/v1/media/whisper/restart",
            post(handle_whisper_restart),
        )
        .route("/api/v1/media/whisper/models/pull", post(handle_whisper_pull))
        .route(
            "/api/v1/media/whisper/models/delete",
            post(handle_whisper_delete),
        )
}

/// Model files are only ever downloaded from whisper.cpp's own repository.
const WHISPER_DOWNLOAD_BASE: &str = "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/";

/// A model download running in the background (or one that failed).
#[derive(Clone, Serialize)]
struct WhisperPull {
    file: String,
    downloaded: u64,
    total: Option<u64>,
    error: Option<String>,
}

static WHISPER_PULLS: std::sync::Mutex<Vec<WhisperPull>> = std::sync::Mutex::new(Vec::new());

fn whisper_pulls() -> Vec<WhisperPull> {
    WHISPER_PULLS
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
        .clone()
}

fn update_pull(file: &str, change: impl FnOnce(&mut WhisperPull)) {
    let mut pulls = WHISPER_PULLS
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner());
    if let Some(pull) = pulls.iter_mut().find(|pull| pull.file == file) {
        change(pull);
    }
}

/// `ggml-<name>.bin` with plain characters only: a file in whisper.cpp's repo.
fn valid_download_name(name: &str) -> bool {
    name.len() <= 100
        && name.starts_with("ggml-")
        && name.ends_with(".bin")
        && name
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_' || c == '.')
        && !name.contains("..")
}

#[derive(Deserialize)]
struct WhisperFileRequest {
    file: String,
}

async fn handle_whisper_pull(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<WhisperFileRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    let file = req.file;
    if !valid_download_name(&file) {
        return ApiResponse::error(&format!("Not a whisper.cpp model file: {file}"))
            .into_response();
    }
    if whisper_models_dir().join(&file).is_file() {
        return ApiResponse::error(&format!("{file} is already downloaded")).into_response();
    }
    {
        let mut pulls = WHISPER_PULLS
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        if pulls
            .iter()
            .any(|pull| pull.file == file && pull.error.is_none())
        {
            return ApiResponse::ok_data("Already downloading", whisper_status()).into_response();
        }
        pulls.retain(|pull| pull.file != file);
        pulls.push(WhisperPull {
            file: file.clone(),
            downloaded: 0,
            total: None,
            error: None,
        });
    }

    tokio::spawn(async move {
        match download_whisper_model(&file).await {
            Ok(()) => WHISPER_PULLS
                .lock()
                .unwrap_or_else(|poisoned| poisoned.into_inner())
                .retain(|pull| pull.file != file),
            Err(error) => update_pull(&file, |pull| pull.error = Some(error)),
        }
    });

    ApiResponse::ok_data("Download started", whisper_status()).into_response()
}

/// Streams the file to `<file>.part` and renames it once complete, so a
/// half-downloaded model is never offered or loaded.
async fn download_whisper_model(file: &str) -> Result<(), String> {
    use futures_util::StreamExt;
    use tokio::io::AsyncWriteExt;

    let dir = whisper_models_dir();
    tokio::fs::create_dir_all(&dir)
        .await
        .map_err(|error| format!("cannot create {}: {error}", dir.display()))?;
    let part = dir.join(format!("{file}.part"));

    let client = reqwest::Client::builder()
        .connect_timeout(Duration::from_secs(30))
        .build()
        .map_err(|error| error.to_string())?;
    let response = client
        .get(format!("{WHISPER_DOWNLOAD_BASE}{file}"))
        .send()
        .await
        .map_err(|error| format!("download failed: {error}"))?;
    if !response.status().is_success() {
        return Err(format!("download failed: HTTP {}", response.status()));
    }
    let total = response.content_length();
    update_pull(file, |pull| pull.total = total);

    let mut out = tokio::fs::File::create(&part)
        .await
        .map_err(|error| error.to_string())?;
    let mut stream = response.bytes_stream();
    let mut downloaded = 0u64;
    while let Some(chunk) = stream.next().await {
        let chunk = match chunk {
            Ok(chunk) => chunk,
            Err(error) => {
                let _ = tokio::fs::remove_file(&part).await;
                return Err(format!("download interrupted: {error}"));
            }
        };
        if let Err(error) = out.write_all(&chunk).await {
            let _ = tokio::fs::remove_file(&part).await;
            return Err(format!("cannot write the model file: {error}"));
        }
        downloaded += chunk.len() as u64;
        update_pull(file, |pull| pull.downloaded = downloaded);
    }
    out.flush().await.map_err(|error| error.to_string())?;
    drop(out);

    if total.is_some_and(|total| total != downloaded) {
        let _ = tokio::fs::remove_file(&part).await;
        return Err("download was incomplete".into());
    }
    tokio::fs::rename(&part, dir.join(file))
        .await
        .map_err(|error| error.to_string())
}

async fn handle_whisper_delete(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<WhisperFileRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    let file = req.file;

    // a failed download is just dismissed
    if !whisper_models_dir().join(&file).is_file() {
        WHISPER_PULLS
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner())
            .retain(|pull| pull.file != file || pull.error.is_none());
        return ApiResponse::ok_data("Removed", whisper_status()).into_response();
    }
    if !valid_model_file(&file) {
        return ApiResponse::error(&format!("No such model file: {file}")).into_response();
    }
    let in_use = std::path::Path::new(&whisper_model_path())
        .file_name()
        .is_some_and(|name| name.to_string_lossy() == file);
    if in_use {
        return ApiResponse::error(&format!(
            "{file} is the model in use: pick another model first"
        ))
        .into_response();
    }
    match std::fs::remove_file(whisper_models_dir().join(&file)) {
        Ok(()) => ApiResponse::ok_data("Removed", whisper_status()).into_response(),
        Err(error) => {
            ApiResponse::error(&format!("Could not remove {file}: {error}")).into_response()
        }
    }
}

#[derive(Deserialize)]
struct WhisperSettingsRequest {
    /// A model file name from the status's `models`.
    #[serde(default)]
    model: Option<String>,
    /// CPU threads per run; null or 0 = automatic (every core up to 8).
    #[serde(default)]
    threads: Option<i32>,
}

/// What the panel's Voice to Text page shows.
fn whisper_status() -> serde_json::Value {
    let mut models: Vec<serde_json::Value> = std::fs::read_dir(whisper_models_dir())
        .map(|entries| {
            entries
                .flatten()
                .filter_map(|entry| {
                    let name = entry.file_name().to_string_lossy().to_string();
                    let size = entry.metadata().ok()?.len();
                    name.ends_with(".bin")
                        .then(|| serde_json::json!({ "file": name, "size_mb": size / 1024 / 1024 }))
                })
                .collect()
        })
        .unwrap_or_default();
    models.sort_by(|a, b| a["file"].as_str().cmp(&b["file"].as_str()));

    let settings = load_whisper_settings();
    let model_path = whisper_model_path();
    let file_name = |path: &str| {
        std::path::Path::new(path)
            .file_name()
            .map(|name| name.to_string_lossy().to_string())
    };
    let model_from = if settings.model.as_deref().is_some_and(valid_model_file) {
        "panel"
    } else if std::env::var("DRUST_WHISPER_MODEL").is_ok() {
        "env"
    } else {
        "default"
    };

    serde_json::json!({
        "speech_built": cfg!(feature = "speech"),
        "models": models,
        "model": file_name(&model_path),
        "model_from": model_from,
        "loaded": loaded_model_path().as_deref().and_then(file_name),
        "threads": whisper_threads(),
        "threads_setting": settings.threads,
        "cpus": num_cpus(),
        "pulls": whisper_pulls(),
    })
}

async fn handle_whisper_status(State(state): State<Arc<ApiState>>, headers: HeaderMap) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    ApiResponse::ok_data("Voice to text", whisper_status()).into_response()
}

async fn handle_whisper_settings(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<WhisperSettingsRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    let mut settings = load_whisper_settings();
    if let Some(model) = req.model.filter(|m| !m.is_empty()) {
        if !valid_model_file(&model) {
            return ApiResponse::error(&format!("No such model file: {model}")).into_response();
        }
        settings.model = Some(model);
    }
    let cpus = num_cpus();
    settings.threads = match req.threads {
        Some(t) if t >= 1 && t <= cpus => Some(t),
        Some(0) | None => None,
        Some(t) => {
            return ApiResponse::error(&format!(
                "threads must be 1 to {cpus} (0 = automatic), got {t}"
            ))
            .into_response();
        }
    };
    if let Err(error) = save_whisper_settings(&settings) {
        return ApiResponse::error(&format!("Could not save settings: {error}")).into_response();
    }
    // a different model is loaded on the next voice note (or right away by restart)
    ApiResponse::ok_data("Saved", whisper_status()).into_response()
}

/// Unloads the model and loads it again (picking up a changed model), without
/// restarting drust itself, so running backups/deploys are not cut off.
async fn handle_whisper_restart(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    let started = std::time::Instant::now();
    match tokio::task::spawn_blocking(restart_whisper).await {
        Ok(Ok(())) => {
            let mut status = whisper_status();
            status["load_ms"] = serde_json::json!(started.elapsed().as_millis() as u64);
            ApiResponse::ok_data("Voice to text restarted", status).into_response()
        }
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("restart failed: {error}")).into_response(),
    }
}

#[derive(Deserialize)]
struct MediaRequest {
    #[serde(default)]
    url: Option<String>,
    /// The file itself, base64-encoded, instead of a url.
    #[serde(default)]
    data: Option<String>,
    /// ISO 639-1 language hint for transcription (e.g. "bn", "en"). Omitted
    /// or "auto" lets whisper.cpp detect the spoken language itself.
    language: Option<String>,
    /// Translate the speech into English instead of writing it down as spoken.
    #[serde(default)]
    translate: bool,
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

    let bytes = match media_bytes(&req).await {
        Ok(bytes) => bytes,
        Err(error) => return ApiResponse::error(&error).into_response(),
    };

    let language = req.language;
    let translate = req.translate;
    let started = std::time::Instant::now();
    match tokio::task::spawn_blocking(move || transcribe(&bytes, language.as_deref(), translate))
        .await
    {
        Ok(Ok(text)) => ApiResponse::ok_data(
            "Transcribed",
            serde_json::json!({ "text": text, "ms": started.elapsed().as_millis() as u64 }),
        )
        .into_response(),
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

    let bytes = match media_bytes(&req).await {
        Ok(bytes) => bytes,
        Err(error) => return ApiResponse::error(&error).into_response(),
    };

    match tokio::task::spawn_blocking(move || ocr(&bytes)).await {
        Ok(Ok(text)) => ApiResponse::ok_data("Extracted text", serde_json::json!({ "text": text }))
            .into_response(),
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("OCR worker failed: {error}")).into_response(),
    }
}

async fn media_bytes(req: &MediaRequest) -> Result<Vec<u8>, String> {
    use base64::Engine;

    match (&req.data, &req.url) {
        (Some(data), _) => {
            let bytes = base64::engine::general_purpose::STANDARD
                .decode(data.trim())
                .map_err(|error| format!("data is not valid base64: {error}"))?;
            if bytes.len() > MAX_MEDIA_BYTES {
                return Err("media exceeds the 25MB size limit".into());
            }
            Ok(bytes)
        }
        (None, Some(url)) => download(url, &req.headers).await,
        (None, None) => Err("pass url or data".into()),
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

#[cfg(not(feature = "speech"))]
fn transcribe(_bytes: &[u8], _language: Option<&str>, _translate: bool) -> Result<String, String> {
    Err(
        "Voice transcription is not available on this server: drust was built without \
         speech support."
            .into(),
    )
}

/// Decodes whatever container/codec ffmpeg recognizes (ogg/opus voice
/// notes, mp3, m4a, webm, ...) down to 16kHz mono PCM — the exact format
/// whisper.cpp expects — rather than trying to support every audio codec
/// natively in Rust.
#[cfg(feature = "speech")]
fn transcribe(bytes: &[u8], language: Option<&str>, translate: bool) -> Result<String, String> {
    let input = tempfile::Builder::new()
        .suffix(".input")
        .tempfile()
        .map_err(|error| error.to_string())?;
    std::fs::write(input.path(), bytes).map_err(|error| error.to_string())?;

    let wav = tempfile::Builder::new()
        .suffix(".wav")
        .tempfile()
        .map_err(|error| error.to_string())?;

    let status = std::process::Command::new("ffmpeg")
        .args(["-y", "-loglevel", "error", "-i"])
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

    let _run = WHISPER_RUN
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner());
    let ctx = whisper_context()?;
    let mut state = ctx
        .create_state()
        .map_err(|error| format!("failed to init speech model: {error}"))?;

    let mut params =
        whisper_rs::FullParams::new(whisper_rs::SamplingStrategy::Greedy { best_of: 1 });
    params.set_n_threads(whisper_threads());
    params.set_translate(translate);
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

#[cfg(not(feature = "ocr"))]
fn ocr(_bytes: &[u8]) -> Result<String, String> {
    Err(
        "Image OCR is not available on this server: drust was built without Tesseract 5 \
         (the operating system ships an older version)."
            .into(),
    )
}

/// Bengali + English are the two languages the panel's own userbase
/// actually needs; tesseract can be fed more `traineddata` files later if
/// another language becomes necessary.
#[cfg(feature = "ocr")]
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
/// transcription slow regardless of audio length. It's loaded once and
/// reused; only `create_state()` (cheap) runs per request. When the panel
/// picks another model file the next request (or a restart) loads that one.
#[cfg(feature = "speech")]
struct LoadedWhisper {
    path: String,
    ctx: std::sync::Arc<whisper_rs::WhisperContext>,
}

#[cfg(feature = "speech")]
static WHISPER: std::sync::Mutex<Option<LoadedWhisper>> = std::sync::Mutex::new(None);

/// One transcription at a time: each run already uses every thread it is
/// given, so parallel runs only slowed each other down until all timed out.
#[cfg(feature = "speech")]
static WHISPER_RUN: std::sync::Mutex<()> = std::sync::Mutex::new(());

#[cfg(feature = "speech")]
fn whisper_context() -> Result<std::sync::Arc<whisper_rs::WhisperContext>, String> {
    let path = whisper_model_path();
    let mut loaded = WHISPER
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner());
    if let Some(current) = loaded.as_ref().filter(|current| current.path == path) {
        return Ok(current.ctx.clone());
    }

    let ctx = whisper_rs::WhisperContext::new_with_params(
        &path,
        whisper_rs::WhisperContextParameters::default(),
    )
    .map_err(|error| format!("failed to load speech model {path}: {error}"))?;
    let ctx = std::sync::Arc::new(ctx);
    *loaded = Some(LoadedWhisper {
        path,
        ctx: ctx.clone(),
    });
    Ok(ctx)
}

#[cfg(feature = "speech")]
fn loaded_model_path() -> Option<String> {
    WHISPER
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
        .as_ref()
        .map(|current| current.path.clone())
}

#[cfg(not(feature = "speech"))]
fn loaded_model_path() -> Option<String> {
    None
}

#[cfg(feature = "speech")]
fn restart_whisper() -> Result<(), String> {
    // waits for a running transcription to finish first
    let _run = WHISPER_RUN
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner());
    WHISPER
        .lock()
        .unwrap_or_else(|poisoned| poisoned.into_inner())
        .take();
    whisper_context().map(|_| ())
}

#[cfg(not(feature = "speech"))]
fn restart_whisper() -> Result<(), String> {
    Err(
        "Voice transcription is not available on this server: drust was built without \
         speech support."
            .into(),
    )
}

fn num_cpus() -> i32 {
    std::thread::available_parallelism()
        .map(|value| value.get() as i32)
        .unwrap_or(4)
}

#[cfg(all(test, not(feature = "speech")))]
mod tests {
    #[test]
    fn transcribe_reports_that_speech_is_not_built_in() {
        let error = super::transcribe(b"audio", None, false).unwrap_err();
        assert!(error.contains("without speech support"), "{error}");
    }
}
