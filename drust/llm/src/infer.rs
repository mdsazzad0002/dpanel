use crate::registry::{LoadedModel, Registry};
use llama_cpp_2::{
    context::params::LlamaContextParams,
    llama_batch::LlamaBatch,
    model::{LlamaChatMessage, LlamaChatTemplate},
    sampling::LlamaSampler,
};
use std::{num::NonZeroU32, sync::Arc};
use tokio::sync::mpsc;

pub struct GenParams {
    pub max_tokens: u32,
    pub temperature: f32,
    pub top_p: f32,
    pub frequency_penalty: f32,
    pub presence_penalty: f32,
    pub seed: u32,
    pub stop: Vec<String>,
    pub ctx_size: u32,
    pub threads: i32,
}

pub enum GenEvent {
    Text(String),
    Done { finish_reason: &'static str, prompt_tokens: usize, completion_tokens: usize },
    Error(String),
}

/// The model's own chat template turns the messages into its prompt format.
/// Older GGUF files carry none; ChatML is what most of those were tuned on.
pub fn render_prompt(model: &LoadedModel, messages: &[(String, String)]) -> Result<String, String> {
    let chat = messages
        .iter()
        .map(|(role, content)| LlamaChatMessage::new(role.clone(), content.clone()))
        .collect::<Result<Vec<_>, _>>()
        .map_err(|error| format!("invalid message: {error}"))?;
    let template = match model.model.chat_template(None) {
        Ok(template) => template,
        Err(_) => LlamaChatTemplate::new("chatml").map_err(|error| error.to_string())?,
    };
    model
        .model
        .apply_chat_template(&template, &chat, true)
        .map_err(|error| format!("chat template failed: {error}"))
}

/// Runs one generation on a worker thread and reports through `tx`. The
/// model's `busy` lock is held for the whole run, so requests queue.
pub fn spawn(
    registry: Arc<Registry>,
    model: Arc<LoadedModel>,
    prompt: String,
    params: GenParams,
    tx: mpsc::Sender<GenEvent>,
) {
    tokio::spawn(async move {
        let _guard = model.busy.clone().lock_owned().await;
        let worker_registry = registry.clone();
        let worker_model = model.clone();
        let worker_tx = tx.clone();
        let result = tokio::task::spawn_blocking(move || {
            if let Err(error) = generate(&worker_registry, &worker_model, &prompt, &params, &worker_tx) {
                let _ = worker_tx.blocking_send(GenEvent::Error(error));
            }
        })
        .await;
        if let Err(error) = result {
            let _ = tx.send(GenEvent::Error(format!("generation crashed: {error}"))).await;
        }
        registry.touch(&model.id).await;
    });
}

fn generate(
    registry: &Registry,
    loaded: &LoadedModel,
    prompt: &str,
    params: &GenParams,
    tx: &mpsc::Sender<GenEvent>,
) -> Result<(), String> {
    let model = &loaded.model;
    let vocab = model.vocab();
    let n_ctx = params.ctx_size.min(model.n_ctx_train()).max(256);
    let n_batch = 512u32.min(n_ctx);

    let tokens = vocab.tokenize(prompt.as_bytes(), true, true);
    let prompt_tokens = tokens.len();
    if prompt_tokens + 1 >= n_ctx as usize {
        return Err(format!(
            "prompt is {prompt_tokens} tokens, the context window is {n_ctx}"
        ));
    }
    let max_tokens = (params.max_tokens as usize).min(n_ctx as usize - prompt_tokens);

    let ctx_params = LlamaContextParams::default()
        .with_n_ctx(NonZeroU32::new(n_ctx))
        .with_n_batch(n_batch)
        .with_n_threads(params.threads)
        .with_n_threads_batch(params.threads);
    let mut ctx = model
        .new_context(registry.backend(), ctx_params)
        .map_err(|error| format!("failed to create context: {error}"))?;

    // Feed the prompt n_batch tokens at a time; only the last token needs logits.
    let mut batch = LlamaBatch::new(n_batch as usize, 1);
    for (chunk_index, chunk) in tokens.chunks(n_batch as usize).enumerate() {
        batch.clear();
        let offset = chunk_index * n_batch as usize;
        for (i, token) in chunk.iter().enumerate() {
            let pos = offset + i;
            batch
                .add(*token, pos as i32, &[0], pos == prompt_tokens - 1)
                .map_err(|error| error.to_string())?;
        }
        ctx.decode(&mut batch).map_err(|error| format!("prompt decode failed: {error}"))?;
    }

    let mut sampler = if params.temperature <= 0.0 {
        LlamaSampler::chain_simple([
            LlamaSampler::penalties(model.n_vocab(), 64, 1.0, params.frequency_penalty, params.presence_penalty),
            LlamaSampler::greedy(),
        ])
    } else {
        LlamaSampler::chain_simple([
            LlamaSampler::penalties(model.n_vocab(), 64, 1.0, params.frequency_penalty, params.presence_penalty),
            LlamaSampler::top_k(40),
            LlamaSampler::top_p(params.top_p, 1),
            LlamaSampler::temp(params.temperature),
            LlamaSampler::dist(params.seed),
        ])
    };

    let mut out = StopFilter::new(&params.stop);
    let mut bytes = Vec::new();
    let mut completion_tokens = 0;
    let mut pos = prompt_tokens as i32;
    let mut finish_reason = "length";

    while completion_tokens < max_tokens {
        let token = sampler.sample(&ctx, batch.n_tokens() - 1);
        if vocab.is_eog(token) {
            finish_reason = "stop";
            break;
        }
        completion_tokens += 1;

        // A token can end mid-character (Bengali, emoji); hold the bytes
        // until the character is complete.
        bytes.extend(vocab.token_to_piece(token, false, None));
        let text = take_utf8(&mut bytes);
        let (emit, stopped) = out.push(&text);
        if !emit.is_empty() && tx.blocking_send(GenEvent::Text(emit)).is_err() {
            return Ok(()); // client went away
        }
        if stopped {
            finish_reason = "stop";
            break;
        }

        batch.clear();
        batch.add(token, pos, &[0], true).map_err(|error| error.to_string())?;
        pos += 1;
        ctx.decode(&mut batch).map_err(|error| format!("decode failed: {error}"))?;
    }

    let rest = out.finish();
    if !rest.is_empty() {
        let _ = tx.blocking_send(GenEvent::Text(rest));
    }
    let _ = tx.blocking_send(GenEvent::Done { finish_reason, prompt_tokens, completion_tokens });
    Ok(())
}

fn take_utf8(bytes: &mut Vec<u8>) -> String {
    match std::str::from_utf8(bytes) {
        Ok(text) => {
            let text = text.to_string();
            bytes.clear();
            text
        }
        Err(error) if error.error_len().is_none() => {
            let valid = error.valid_up_to();
            let text = String::from_utf8_lossy(&bytes[..valid]).into_owned();
            bytes.drain(..valid);
            text
        }
        Err(_) => String::from_utf8_lossy(&std::mem::take(bytes)).into_owned(),
    }
}

/// Holds back text that could be the start of a stop sequence, so a stop
/// string is never half-sent before it is recognised.
struct StopFilter<'a> {
    stops: &'a [String],
    pending: String,
}

impl<'a> StopFilter<'a> {
    fn new(stops: &'a [String]) -> Self {
        Self { stops, pending: String::new() }
    }

    fn push(&mut self, text: &str) -> (String, bool) {
        self.pending.push_str(text);
        if self.stops.is_empty() {
            return (std::mem::take(&mut self.pending), false);
        }
        if let Some(at) = self.stops.iter().filter_map(|stop| self.pending.find(stop.as_str())).min() {
            let emit = self.pending[..at].to_string();
            self.pending.clear();
            return (emit, true);
        }
        let hold = self
            .stops
            .iter()
            .flat_map(|stop| stop.char_indices().skip(1).map(move |(i, _)| &stop[..i]))
            .filter(|prefix| self.pending.ends_with(prefix))
            .map(str::len)
            .max()
            .unwrap_or(0);
        let split = self.pending.len() - hold;
        let emit = self.pending[..split].to_string();
        self.pending.drain(..split);
        (emit, false)
    }

    fn finish(&mut self) -> String {
        std::mem::take(&mut self.pending)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn stop_filter_holds_back_partial_stop() {
        let stops = vec!["</end>".to_string()];
        let mut filter = StopFilter::new(&stops);
        assert_eq!(filter.push("hello </e"), ("hello ".to_string(), false));
        assert_eq!(filter.push("nd> tail"), (String::new(), true));
    }

    #[test]
    fn stop_filter_releases_false_alarm() {
        let stops = vec!["###".to_string()];
        let mut filter = StopFilter::new(&stops);
        assert_eq!(filter.push("a #"), ("a ".to_string(), false));
        assert_eq!(filter.push("b"), ("#b".to_string(), false));
    }

    #[test]
    fn utf8_split_across_tokens() {
        let mut bytes = "আ".as_bytes()[..2].to_vec();
        assert_eq!(take_utf8(&mut bytes), "");
        bytes.extend(&"আ".as_bytes()[2..]);
        assert_eq!(take_utf8(&mut bytes), "আ");
    }
}
