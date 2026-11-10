use crate::{
    AppState, api_error, check_key,
    chat::{self, ChatMessage, Delta, OutputParser},
    infer::{self, GenEvent, GenParams, Job},
    registry::{unix_now, valid_id},
};
use axum::{
    Json, Router,
    extract::State,
    http::{HeaderMap, StatusCode},
    response::{
        IntoResponse, Response,
        sse::{Event, KeepAlive, Sse},
    },
    routing::{get, post},
};
use serde::Deserialize;
use serde_json::{Map, Value, json};
use std::{collections::VecDeque, convert::Infallible, sync::Arc};
use tokio::sync::mpsc;

pub fn routes() -> Router<Arc<AppState>> {
    Router::new()
        .route("/v1/models", get(list_models))
        .route("/v1/chat/completions", post(chat_completions))
}

async fn list_models(State(state): State<Arc<AppState>>, headers: HeaderMap) -> Response {
    if let Err(response) = check_key(&state, &headers) {
        return response;
    }
    let data: Vec<Value> = state
        .registry
        .list(&state.config.models_dir)
        .await
        .into_iter()
        .map(|model| {
            json!({
                "id": model.id,
                "object": "model",
                "created": model.created,
                "owned_by": "drust-llm",
                "loaded": model.loaded,
            })
        })
        .collect();
    Json(json!({ "object": "list", "data": data })).into_response()
}

#[derive(Deserialize)]
struct ChatRequest {
    #[serde(default)]
    model: Option<String>,
    messages: Vec<ChatMessage>,
    #[serde(default)]
    stream: bool,
    #[serde(default)]
    max_tokens: Option<u32>,
    #[serde(default)]
    max_completion_tokens: Option<u32>,
    #[serde(default)]
    temperature: Option<f32>,
    #[serde(default)]
    top_p: Option<f32>,
    #[serde(default)]
    frequency_penalty: Option<f32>,
    #[serde(default)]
    presence_penalty: Option<f32>,
    #[serde(default)]
    seed: Option<u32>,
    #[serde(default)]
    stop: Option<Value>,
    #[serde(default)]
    stream_options: Option<Value>,
    #[serde(default)]
    tools: Option<Vec<Value>>,
    #[serde(default)]
    tool_choice: Option<Value>,
    /// Passed to the chat template, e.g. {"enable_thinking": true}.
    #[serde(default)]
    chat_template_kwargs: Option<Map<String, Value>>,
    /// "none" turns thinking off, any other effort on.
    #[serde(default)]
    reasoning_effort: Option<String>,
    /// Ollama's switch for the same.
    #[serde(default)]
    think: Option<bool>,
}

/// `model` may be omitted or "auto": then the configured default, else
/// the only model on disk.
async fn resolve_model(state: &AppState, requested: Option<&str>) -> Result<String, String> {
    match requested.map(str::trim).filter(|id| !id.is_empty() && *id != "auto") {
        Some(id) => Ok(id.to_string()),
        None => {
            if let Some(id) = &state.config.default_model {
                return Ok(id.clone());
            }
            let models = state.registry.list(&state.config.models_dir).await;
            match models.as_slice() {
                [only] => Ok(only.id.clone()),
                [] => Err("no models installed".into()),
                _ => Err("several models installed; set `model`".into()),
            }
        }
    }
}

async fn chat_completions(
    State(state): State<Arc<AppState>>,
    headers: HeaderMap,
    Json(req): Json<ChatRequest>,
) -> Response {
    if let Err(response) = check_key(&state, &headers) {
        return response;
    }
    let bad = |code: &str, message: &str| api_error(StatusCode::BAD_REQUEST, code, message);

    let model_id = match resolve_model(&state, req.model.as_deref()).await {
        Ok(id) => id,
        Err(error) => return bad("model_not_found", &error),
    };
    if !valid_id(&model_id) {
        return bad("model_not_found", "invalid model id");
    }
    if req.messages.is_empty() {
        return bad("invalid_messages", "messages must not be empty");
    }
    for message in &req.messages {
        if let Err(error) = chat::message_text(&message.content) {
            return bad("invalid_content", error);
        }
    }
    // tool_choice "none": answer in text; the tools stay out of the prompt.
    let tools = match req.tool_choice.as_ref().and_then(Value::as_str) {
        Some("none") => Vec::new(),
        _ => req.tools.clone().unwrap_or_default(),
    };
    let kwargs = req.chat_template_kwargs.clone().unwrap_or_default();
    let thinking = kwargs
        .get("enable_thinking")
        .and_then(Value::as_bool)
        .or(req.think)
        .or_else(|| req.reasoning_effort.as_deref().map(|effort| effort != "none"))
        .unwrap_or(state.config.thinking);
    let stop = match &req.stop {
        None | Some(Value::Null) => Vec::new(),
        Some(Value::String(stop)) => vec![stop.clone()],
        Some(Value::Array(stops)) => stops.iter().filter_map(Value::as_str).map(String::from).collect(),
        Some(_) => return bad("invalid_stop", "stop must be a string or a list of strings"),
    };
    let stop = stop.into_iter().filter(|s| !s.is_empty()).take(4).collect();

    let model = match state.registry.get(&state, &model_id).await {
        Ok(model) => model,
        Err(error) if error.contains("not found") => {
            return api_error(StatusCode::NOT_FOUND, "model_not_found", &error);
        }
        Err(error) => return api_error(StatusCode::SERVICE_UNAVAILABLE, "model_load_failed", &error),
    };
    let prompt = match infer::render_prompt(&model, &req.messages, &tools, thinking, &kwargs) {
        Ok(prompt) => prompt,
        Err(error) => return bad("invalid_messages", &error),
    };
    let parser = OutputParser::new(chat::tool_names(&tools), prompt.think_open);

    let params = GenParams {
        max_tokens: req
            .max_completion_tokens
            .or(req.max_tokens)
            .unwrap_or(state.config.max_tokens)
            .min(state.config.max_tokens)
            .max(1),
        temperature: req.temperature.unwrap_or(0.7).clamp(0.0, 2.0),
        top_p: req.top_p.unwrap_or(0.95).clamp(0.01, 1.0),
        frequency_penalty: req.frequency_penalty.unwrap_or(0.0).clamp(-2.0, 2.0),
        presence_penalty: req.presence_penalty.unwrap_or(0.0).clamp(-2.0, 2.0),
        seed: req.seed.unwrap_or_else(|| unix_now() as u32),
        stop,
        special: !tools.is_empty(),
        reuse_prefix: true,
    };

    let (tx, rx) = mpsc::unbounded_channel();
    if let Err(error) = model.submit(Job { prompt: prompt.text, params, tx }) {
        return api_error(StatusCode::SERVICE_UNAVAILABLE, "model_unavailable", &error);
    }
    state.registry.touch(&model_id).await;

    let id = format!("chatcmpl-{:x}{:x}", unix_now(), rand_suffix());
    let include_usage = req
        .stream_options
        .as_ref()
        .and_then(|options| options.get("include_usage"))
        .and_then(Value::as_bool)
        .unwrap_or(false);
    if req.stream {
        stream_response(rx, parser, id, model_id, include_usage).into_response()
    } else {
        collect_response(rx, parser, id, model_id).await
    }
}

fn rand_suffix() -> u64 {
    use std::hash::{BuildHasher, Hasher};
    let mut hasher = std::collections::hash_map::RandomState::new().build_hasher();
    hasher.write_u64(unix_now());
    hasher.finish() & 0xffff_ffff
}

fn usage(prompt_tokens: usize, completion_tokens: usize) -> Value {
    json!({
        "prompt_tokens": prompt_tokens,
        "completion_tokens": completion_tokens,
        "total_tokens": prompt_tokens + completion_tokens,
    })
}

fn finish_reason_for(reason: &str, calls: &[Value]) -> String {
    if calls.is_empty() { reason.to_string() } else { "tool_calls".to_string() }
}

async fn collect_response(
    mut rx: mpsc::UnboundedReceiver<GenEvent>,
    mut parser: OutputParser,
    id: String,
    model: String,
) -> Response {
    let (mut content, mut reasoning) = (String::new(), String::new());
    let mut take = |deltas: Vec<Delta>| {
        for delta in deltas {
            match delta {
                Delta::Content(text) => content.push_str(&text),
                Delta::Reasoning(text) => reasoning.push_str(&text),
            }
        }
    };
    while let Some(event) = rx.recv().await {
        match event {
            GenEvent::Text(text) => take(parser.push(&text)),
            GenEvent::Error(error) => {
                return api_error(StatusCode::INTERNAL_SERVER_ERROR, "generation_failed", &error);
            }
            GenEvent::Done { finish_reason, prompt_tokens, completion_tokens } => {
                let (deltas, calls) = parser.finish();
                take(deltas);
                let mut message = json!({ "role": "assistant", "content": content.trim() });
                if !reasoning.trim().is_empty() {
                    message["reasoning_content"] = json!(reasoning.trim());
                }
                if !calls.is_empty() {
                    message["tool_calls"] = json!(calls);
                }
                return Json(json!({
                    "id": id,
                    "object": "chat.completion",
                    "created": unix_now(),
                    "model": model,
                    "choices": [{
                        "index": 0,
                        "message": message,
                        "finish_reason": finish_reason_for(finish_reason, &calls),
                    }],
                    "usage": usage(prompt_tokens, completion_tokens),
                }))
                .into_response();
            }
        }
    }
    api_error(StatusCode::INTERNAL_SERVER_ERROR, "generation_failed", "generation ended early")
}

fn stream_response(
    rx: mpsc::UnboundedReceiver<GenEvent>,
    parser: OutputParser,
    id: String,
    model: String,
    include_usage: bool,
) -> Sse<impl futures_util::Stream<Item = Result<Event, Infallible>>> {
    let created = unix_now();
    let chunk = move |delta: Value, finish_reason: Option<&str>| {
        json!({
            "id": id,
            "object": "chat.completion.chunk",
            "created": created,
            "model": model,
            "choices": [{ "index": 0, "delta": delta, "finish_reason": finish_reason }],
        })
        .to_string()
    };
    let delta_chunk = {
        let chunk = chunk.clone();
        move |delta: Delta| match delta {
            Delta::Content(text) => chunk(json!({ "content": text }), None),
            Delta::Reasoning(text) => chunk(json!({ "reasoning_content": text }), None),
        }
    };

    // The first chunk carries the role, as OpenAI's does; tool calls come
    // whole at the end, then the finish reason, the optional usage chunk
    // and [DONE].
    let mut queue = VecDeque::new();
    queue.push_back(chunk(json!({ "role": "assistant", "content": "" }), None));
    let state = (rx, parser, queue, false);
    let stream = futures_util::stream::unfold(state, move |(mut rx, mut parser, mut queue, mut ended)| {
        let chunk = chunk.clone();
        let delta_chunk = delta_chunk.clone();
        async move {
            loop {
                if let Some(next) = queue.pop_front() {
                    return Some((Ok(Event::default().data(next)), (rx, parser, queue, ended)));
                }
                if ended {
                    return None;
                }
                match rx.recv().await {
                    Some(GenEvent::Text(text)) => queue.extend(parser.push(&text).into_iter().map(&delta_chunk)),
                    Some(GenEvent::Done { finish_reason, prompt_tokens, completion_tokens }) => {
                        ended = true;
                        let (deltas, calls) = parser.finish();
                        queue.extend(deltas.into_iter().map(&delta_chunk));
                        if !calls.is_empty() {
                            let indexed: Vec<Value> = calls
                                .iter()
                                .enumerate()
                                .map(|(index, call)| {
                                    let mut call = call.clone();
                                    call["index"] = json!(index);
                                    call
                                })
                                .collect();
                            queue.push_back(chunk(json!({ "tool_calls": indexed }), None));
                        }
                        queue.push_back(chunk(json!({}), Some(&finish_reason_for(finish_reason, &calls))));
                        if include_usage {
                            let mut usage_chunk: Value = serde_json::from_str(&chunk(json!({}), None)).unwrap_or_default();
                            usage_chunk["choices"] = json!([]);
                            usage_chunk["usage"] = usage(prompt_tokens, completion_tokens);
                            queue.push_back(usage_chunk.to_string());
                        }
                        queue.push_back("[DONE]".into());
                    }
                    Some(GenEvent::Error(error)) => {
                        ended = true;
                        queue.push_back(
                            json!({ "error": { "message": error, "type": "server_error", "code": "generation_failed" } })
                                .to_string(),
                        );
                        queue.push_back("[DONE]".into());
                    }
                    None => return None,
                }
            }
        }
    });
    Sse::new(stream).keep_alive(KeepAlive::default())
}
