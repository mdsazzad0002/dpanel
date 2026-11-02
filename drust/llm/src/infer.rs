use crate::registry::{LoadedModel, backend};
use llama_cpp_2::{
    context::{LlamaContext, params::LlamaContextParams},
    llama_batch::LlamaBatch,
    model::{LlamaChatMessage, LlamaChatTemplate, LlamaModel},
    sampling::LlamaSampler,
    token::LlamaToken,
};
use std::{
    num::NonZeroU32,
    path::PathBuf,
    sync::{
        Arc,
        atomic::{AtomicUsize, Ordering},
        mpsc as std_mpsc,
    },
};
use tokio::sync::mpsc;

pub struct GenParams {
    pub max_tokens: u32,
    pub temperature: f32,
    pub top_p: f32,
    pub frequency_penalty: f32,
    pub presence_penalty: f32,
    pub seed: u32,
    pub stop: Vec<String>,
}

pub enum GenEvent {
    Text(String),
    Done { finish_reason: &'static str, prompt_tokens: usize, completion_tokens: usize },
    Error(String),
}

/// One chat request on its way to a model's worker. Events go back on an
/// unbounded channel so one slow client can never stall the shared batch.
pub struct Job {
    pub prompt: String,
    pub params: GenParams,
    pub tx: mpsc::UnboundedSender<GenEvent>,
}

pub struct EngineConfig {
    /// Context window each request gets.
    pub ctx_size: u32,
    /// Requests decoded together in one batch.
    pub parallel: u32,
    pub threads: i32,
}

#[derive(Default)]
pub struct Stats {
    pub active: AtomicUsize,
    pub queued: AtomicUsize,
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

pub struct Engine {
    pub model: Arc<LlamaModel>,
    pub jobs: std_mpsc::Sender<Job>,
    pub stats: Arc<Stats>,
    pub ctx_per_request: u32,
}

/// Loads the model and starts its worker thread; blocks until the worker's
/// context exists, so an allocation failure is reported to the caller.
///
/// The worker owns a single context with one KV-cache sequence per slot
/// (continuous batching): every step decodes one token for each running
/// request in a single llama_decode call. On a CPU decoding is bound by
/// reading the weights from RAM, and a batch reads them once per step for
/// all requests, so concurrent requests cost far less than running them
/// one after another. The worker ends when the last Sender is dropped.
pub fn start(path: PathBuf, config: EngineConfig) -> Result<Engine, String> {
    let model = Arc::new(
        LlamaModel::load_from_file(backend(), &path, &Default::default())
            .map_err(|error| format!("failed to load model: {error}"))?,
    );
    let ctx_per_request = config.ctx_size.min(model.n_ctx_train()).max(256);
    let parallel = config.parallel.max(1);
    let stats = Arc::new(Stats::default());
    let (jobs_tx, jobs_rx) = std_mpsc::channel::<Job>();
    let (ready_tx, ready_rx) = std_mpsc::channel::<Result<(), String>>();

    let worker_model = model.clone();
    let worker_stats = stats.clone();
    std::thread::Builder::new()
        .name("llm-worker".into())
        .spawn(move || {
            let model = worker_model;
            let params = LlamaContextParams::default()
                .with_n_ctx(NonZeroU32::new(ctx_per_request * parallel))
                .with_n_seq_max(parallel)
                .with_n_batch(N_BATCH)
                .with_n_threads(config.threads)
                .with_n_threads_batch(config.threads);
            let ctx = match model.new_context(backend(), params) {
                Ok(ctx) => {
                    let _ = ready_tx.send(Ok(()));
                    ctx
                }
                Err(error) => {
                    let _ = ready_tx.send(Err(format!("failed to create context: {error}")));
                    return;
                }
            };
            Worker::new(ctx, &model, parallel as usize, ctx_per_request, worker_stats).run(jobs_rx);
        })
        .map_err(|error| format!("cannot start worker: {error}"))?;

    ready_rx.recv().map_err(|_| "worker exited during start".to_string())??;
    Ok(Engine { model, jobs: jobs_tx, stats, ctx_per_request })
}

const N_BATCH: u32 = 512;

struct Slot {
    seq: i32,
    tx: mpsc::UnboundedSender<GenEvent>,
    sampler: LlamaSampler,
    filter: StopFilter,
    bytes: Vec<u8>,
    prompt: Vec<LlamaToken>,
    /// Prompt tokens already in the KV cache.
    prefilled: usize,
    n_past: i32,
    completion_tokens: usize,
    max_tokens: usize,
    /// Sampled token still to be fed back in the next step.
    next: Option<LlamaToken>,
    /// Where this slot's logits sit in the batch just decoded.
    logits_at: Option<i32>,
}

struct Worker<'m> {
    ctx: LlamaContext<'m>,
    model: &'m LlamaModel,
    slots: Vec<Option<Slot>>,
    ctx_per_request: u32,
    stats: Arc<Stats>,
}

impl<'m> Worker<'m> {
    fn new(ctx: LlamaContext<'m>, model: &'m LlamaModel, parallel: usize, ctx_per_request: u32, stats: Arc<Stats>) -> Self {
        Self { ctx, model, slots: (0..parallel).map(|_| None).collect(), ctx_per_request, stats }
    }

    fn active(&self) -> usize {
        self.slots.iter().flatten().count()
    }

    fn run(mut self, jobs: std_mpsc::Receiver<Job>) {
        let mut batch = LlamaBatch::new(N_BATCH as usize, self.slots.len() as i32);
        loop {
            // Idle: sleep until a request arrives (or every Sender is gone).
            if self.active() == 0 {
                match jobs.recv() {
                    Ok(job) => self.admit(job),
                    Err(_) => return,
                }
            }
            // Fill free slots with whatever else is waiting.
            while self.slots.iter().any(Option::is_none) {
                match jobs.try_recv() {
                    Ok(job) => self.admit(job),
                    Err(_) => break,
                }
            }
            self.stats.active.store(self.active(), Ordering::Relaxed);
            if self.active() == 0 {
                continue;
            }
            if let Err(error) = self.step(&mut batch) {
                self.fail_all(&error);
            }
        }
    }

    fn admit(&mut self, job: Job) {
        self.stats.queued.fetch_sub(1, Ordering::Relaxed);
        let Some(index) = self.slots.iter().position(Option::is_none) else { return };
        let prompt = self.model.vocab().tokenize(job.prompt.as_bytes(), true, true);
        let limit = self.ctx_per_request as usize;
        if prompt.is_empty() || prompt.len() + 1 >= limit {
            let _ = job.tx.send(GenEvent::Error(format!(
                "prompt is {} tokens, the context window is {limit}",
                prompt.len()
            )));
            return;
        }
        let seq = index as i32;
        let _ = self.ctx.clear_kv_cache_seq(Some(seq as u32), None, None);
        let max_tokens = (job.params.max_tokens as usize).min(limit - prompt.len());
        self.slots[index] = Some(Slot {
            seq,
            sampler: sampler(self.model, &job.params),
            filter: StopFilter::new(job.params.stop),
            tx: job.tx,
            bytes: Vec::new(),
            prompt,
            prefilled: 0,
            n_past: 0,
            completion_tokens: 0,
            max_tokens,
            next: None,
            logits_at: None,
        });
    }

    /// One llama_decode: the next token of every generating request, plus
    /// as much pending prompt as still fits in the batch.
    fn step(&mut self, batch: &mut LlamaBatch) -> Result<(), String> {
        batch.clear();
        for slot in self.slots.iter_mut().flatten() {
            slot.logits_at = None;
            if let Some(token) = slot.next.take() {
                slot.logits_at = Some(batch.n_tokens());
                batch.add(token, slot.n_past, &[slot.seq], true).map_err(|e| e.to_string())?;
                slot.n_past += 1;
            }
        }
        for slot in self.slots.iter_mut().flatten() {
            let room = N_BATCH as usize - batch.n_tokens() as usize;
            if room == 0 {
                break;
            }
            let remaining = slot.prompt.len() - slot.prefilled;
            for _ in 0..remaining.min(room) {
                let last = slot.prefilled == slot.prompt.len() - 1;
                if last {
                    slot.logits_at = Some(batch.n_tokens());
                }
                batch
                    .add(slot.prompt[slot.prefilled], slot.n_past, &[slot.seq], last)
                    .map_err(|e| e.to_string())?;
                slot.prefilled += 1;
                slot.n_past += 1;
            }
        }
        if batch.n_tokens() == 0 {
            return Ok(());
        }
        self.ctx.decode(batch).map_err(|error| format!("decode failed: {error}"))?;

        let model = self.model;
        let vocab = model.vocab();
        for index in 0..self.slots.len() {
            let Some(slot) = self.slots[index].as_mut() else { continue };
            let Some(at) = slot.logits_at else { continue };
            let token = slot.sampler.sample(&self.ctx, at);
            let prompt_tokens = slot.prompt.len();

            let finish = if vocab.is_eog(token) {
                Some("stop")
            } else {
                slot.completion_tokens += 1;
                // A token can end mid-character (Bengali, emoji); hold the
                // bytes until the character is complete.
                slot.bytes.extend(vocab.token_to_piece(token, false, None));
                let text = take_utf8(&mut slot.bytes);
                let (emit, stopped) = slot.filter.push(&text);
                if !emit.is_empty() && slot.tx.send(GenEvent::Text(emit)).is_err() {
                    self.release(index); // client went away
                    continue;
                }
                if stopped {
                    Some("stop")
                } else if slot.completion_tokens >= slot.max_tokens {
                    Some("length")
                } else {
                    slot.next = Some(token);
                    None
                }
            };
            if let Some(finish_reason) = finish {
                let rest = slot.filter.finish();
                if !rest.is_empty() {
                    let _ = slot.tx.send(GenEvent::Text(rest));
                }
                let completion_tokens = slot.completion_tokens;
                let _ = slot.tx.send(GenEvent::Done { finish_reason, prompt_tokens, completion_tokens });
                self.release(index);
            }
        }
        Ok(())
    }

    fn release(&mut self, index: usize) {
        if let Some(slot) = self.slots[index].take() {
            let _ = self.ctx.clear_kv_cache_seq(Some(slot.seq as u32), None, None);
        }
    }

    fn fail_all(&mut self, error: &str) {
        for index in 0..self.slots.len() {
            if let Some(slot) = self.slots[index].as_ref() {
                let _ = slot.tx.send(GenEvent::Error(error.to_string()));
            }
            self.release(index);
        }
    }
}

fn sampler(model: &LlamaModel, params: &GenParams) -> LlamaSampler {
    let penalties =
        LlamaSampler::penalties(model.n_vocab(), 64, 1.0, params.frequency_penalty, params.presence_penalty);
    if params.temperature <= 0.0 {
        LlamaSampler::chain_simple([penalties, LlamaSampler::greedy()])
    } else {
        LlamaSampler::chain_simple([
            penalties,
            LlamaSampler::top_k(40),
            LlamaSampler::top_p(params.top_p, 1),
            LlamaSampler::temp(params.temperature),
            LlamaSampler::dist(params.seed),
        ])
    }
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
struct StopFilter {
    stops: Vec<String>,
    pending: String,
}

impl StopFilter {
    fn new(stops: Vec<String>) -> Self {
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
        let mut filter = StopFilter::new(stops);
        assert_eq!(filter.push("hello </e"), ("hello ".to_string(), false));
        assert_eq!(filter.push("nd> tail"), (String::new(), true));
    }

    #[test]
    fn stop_filter_releases_false_alarm() {
        let stops = vec!["###".to_string()];
        let mut filter = StopFilter::new(stops);
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
