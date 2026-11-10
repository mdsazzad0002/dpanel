use crate::{
    chat::{self, ChatMessage, TemplateInput},
    profile::Speed,
    registry::{LoadedModel, backend},
};
use llama_cpp_2::{
    context::{LlamaContext, params::LlamaContextParams},
    llama_batch::LlamaBatch,
    model::{LlamaChatMessage, LlamaChatTemplate, LlamaModel, params::LlamaModelParams},
    sampling::LlamaSampler,
    token::LlamaToken,
};
use serde_json::{Map, Value, json};
use std::{
    collections::VecDeque,
    num::NonZeroU32,
    path::PathBuf,
    sync::{
        Arc,
        Mutex as StdMutex,
        atomic::{AtomicBool, AtomicUsize, Ordering},
        mpsc as std_mpsc,
    },
    time::Instant,
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
    /// Write out special tokens too: tool-call markers such as Mistral's
    /// [TOOL_CALLS] or Llama's <|python_tag|> are special tokens.
    pub special: bool,
    /// Start from a cached sequence sharing the prompt's beginning. Off for
    /// the self-test, which must time the whole prompt.
    pub reuse_prefix: bool,
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
    /// KV cache shared by all requests (and kept prefixes), in tokens.
    pub kv_size: u32,
    /// Requests decoded together in one batch.
    pub parallel: u32,
    pub threads: i32,
    /// Layers placed on the GPU; 0 keeps everything on the CPU.
    pub gpu_layers: u32,
}

#[derive(Default)]
pub struct Stats {
    pub active: AtomicUsize,
    pub queued: AtomicUsize,
    /// Learned from finished requests; `speed_dirty` until saved to the profile.
    pub speed: StdMutex<Option<Speed>>,
    pub speed_dirty: AtomicBool,
}

impl Stats {
    fn record_speed(&self, prompt_tps: Option<f64>, gen_tps: Option<f64>) {
        if prompt_tps.is_none() && gen_tps.is_none() {
            return;
        }
        if let Ok(mut speed) = self.speed.lock() {
            Speed::record(&mut speed, prompt_tps, gen_tps);
            self.speed_dirty.store(true, Ordering::Relaxed);
        }
    }
}

pub struct Prompt {
    pub text: String,
    /// The prompt ends inside an opened <think> block.
    pub think_open: bool,
}

/// The model's own chat template turns the messages into its prompt format:
/// its Jinja template first (with tools when it supports them, else tools
/// described in the system message), then llama.cpp's built-in formats,
/// then ChatML, which most GGUF files without a template were tuned on.
pub fn render_prompt(
    model: &LoadedModel,
    messages: &[ChatMessage],
    tools: &[Value],
    thinking: bool,
    kwargs: &Map<String, Value>,
) -> Result<Prompt, String> {
    let llama = &model.model;
    let vocab = llama.vocab();
    let piece = |token| String::from_utf8_lossy(&vocab.token_to_piece(token, true, None)).into_owned();
    let (bos, eos) = (piece(vocab.bos()), piece(vocab.eos()));
    let generic = chat::generic_messages(messages, tools)?;
    let finish = |mut text: String| {
        // The tokenizer adds BOS itself; a second one confuses some models.
        if vocab.should_add_bos() && !bos.is_empty() && text.starts_with(&bos) {
            text.drain(..bos.len());
        }
        let think_open = text.trim_end().ends_with("<think>");
        Prompt { text, think_open }
    };

    let source = llama.chat_template(None).ok().and_then(|template| template.to_string().ok());
    if let Some(source) = source.as_deref() {
        let input = TemplateInput { source, bos: &bos, eos: &eos, tools, thinking, kwargs };
        if !tools.is_empty() && chat::native_tools(source) {
            match chat::native_messages(messages, source).and_then(|values| chat::render_jinja(&input, &values, true)) {
                Ok(text) => return Ok(finish(text)),
                Err(error) => tracing::debug!(model = %model.id, "template with tools failed: {error}"),
            }
        }
        let values: Vec<Value> = generic.iter().map(|(role, content)| json!({ "role": role, "content": content })).collect();
        match chat::render_jinja(&input, &values, false) {
            Ok(text) => return Ok(finish(text)),
            Err(error) => tracing::debug!(model = %model.id, "template failed, using llama.cpp's: {error}"),
        }
    }

    let chat = generic
        .iter()
        .map(|(role, content)| LlamaChatMessage::new(role.clone(), content.clone()))
        .collect::<Result<Vec<_>, _>>()
        .map_err(|error| format!("invalid message: {error}"))?;
    let builtin = llama
        .chat_template(None)
        .ok()
        .and_then(|template| llama.apply_chat_template(&template, &chat, true).ok());
    let text = match builtin {
        Some(text) => text,
        None => {
            let chatml = LlamaChatTemplate::new("chatml").map_err(|error| error.to_string())?;
            llama.apply_chat_template(&chatml, &chat, true).map_err(|error| format!("chat template failed: {error}"))?
        }
    };
    Ok(finish(text))
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
///
/// The KV cache is unified: all sequences share `kv_size` tokens, so one
/// request can use the whole window (the panel's tools alone are ~7k
/// tokens) while short ones still run side by side. A finished request's
/// tokens stay cached, and the next request that starts the same way (the
/// next round of a tool loop) only reads what is new.
pub fn start(path: PathBuf, config: EngineConfig) -> Result<Engine, String> {
    let model = Arc::new(
        LlamaModel::load_from_file(backend(), &path, &LlamaModelParams::default().with_n_gpu_layers(config.gpu_layers))
            .map_err(|error| format!("failed to load model: {error}"))?,
    );
    let ctx_per_request = config.ctx_size.min(model.n_ctx_train()).min(config.kv_size.max(256)).max(256);
    let kv_size = config.kv_size.max(ctx_per_request);
    let parallel = config.parallel.max(1);
    // Sliding-window and recurrent caches drop old tokens, so a cached
    // prefix can't be trusted to still be there.
    let arch = model.meta_val_str("general.architecture").unwrap_or_default();
    let sliding = model
        .meta_val_str(&format!("{arch}.attention.sliding_window"))
        .is_ok_and(|value| value.trim().parse::<u64>().map_or(true, |n| n > 0));
    let reuse = !sliding && !model.is_recurrent() && !model.is_hybrid();
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
                .with_n_ctx(NonZeroU32::new(kv_size))
                .with_n_seq_max(parallel)
                .with_kv_unified(true)
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
            Worker::new(ctx, &model, parallel as usize, ctx_per_request, kv_size as usize, reuse, worker_stats).run(jobs_rx);
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
    special: bool,
    bytes: Vec<u8>,
    prompt: Vec<LlamaToken>,
    /// Prompt tokens already in the KV cache (a reused prefix included).
    prefilled: usize,
    /// Of those, how many came from the cache.
    reused: usize,
    n_past: i32,
    completion_tokens: usize,
    max_tokens: usize,
    /// Sampled token still to be fed back in the next step.
    next: Option<LlamaToken>,
    /// Where this slot's logits sit in the batch just decoded.
    logits_at: Option<i32>,
    admitted: Instant,
    first_token: Option<Instant>,
}

impl Slot {
    /// KV cells this request may still take.
    fn remaining(&self) -> usize {
        (self.prompt.len() - self.prefilled) + self.max_tokens.saturating_sub(self.completion_tokens)
    }
}

/// A job waiting for a slot, already tokenized.
struct Waiting {
    job: Job,
    prompt: Vec<LlamaToken>,
}

struct Worker<'m> {
    ctx: LlamaContext<'m>,
    model: &'m LlamaModel,
    slots: Vec<Option<Slot>>,
    /// Tokens in each sequence's KV cache; kept after a request ends.
    cached: Vec<Vec<LlamaToken>>,
    /// When each sequence's last request ended (to evict the oldest first).
    idle_since: Vec<Instant>,
    waiting: VecDeque<Waiting>,
    ctx_per_request: u32,
    kv_size: usize,
    reuse: bool,
    stats: Arc<Stats>,
}

impl<'m> Worker<'m> {
    fn new(
        ctx: LlamaContext<'m>,
        model: &'m LlamaModel,
        parallel: usize,
        ctx_per_request: u32,
        kv_size: usize,
        reuse: bool,
        stats: Arc<Stats>,
    ) -> Self {
        Self {
            ctx,
            model,
            slots: (0..parallel).map(|_| None).collect(),
            cached: vec![Vec::new(); parallel],
            idle_since: vec![Instant::now(); parallel],
            waiting: VecDeque::new(),
            ctx_per_request,
            kv_size,
            reuse,
            stats,
        }
    }

    fn active(&self) -> usize {
        self.slots.iter().flatten().count()
    }

    fn run(mut self, jobs: std_mpsc::Receiver<Job>) {
        let mut batch = LlamaBatch::new(N_BATCH as usize, self.slots.len() as i32);
        loop {
            // Idle: sleep until a request arrives (or every Sender is gone).
            if self.active() == 0 && self.waiting.is_empty() {
                match jobs.recv() {
                    Ok(job) => self.enqueue(job),
                    Err(_) => return,
                }
            }
            while let Ok(job) = jobs.try_recv() {
                self.enqueue(job);
            }
            self.admit_waiting();
            self.stats.active.store(self.active(), Ordering::Relaxed);
            if self.active() == 0 {
                continue;
            }
            if let Err(error) = self.step(&mut batch) {
                self.fail_all(&error);
            }
            // Again after the step, or the status page keeps showing the last
            // request as running while the worker waits idle in recv().
            self.stats.active.store(self.active(), Ordering::Relaxed);
        }
    }

    fn enqueue(&mut self, job: Job) {
        let prompt = self.model.vocab().tokenize(job.prompt.as_bytes(), true, true);
        let limit = self.ctx_per_request as usize;
        if prompt.is_empty() || prompt.len() + 1 >= limit {
            self.stats.queued.fetch_sub(1, Ordering::Relaxed);
            let _ = job.tx.send(GenEvent::Error(format!(
                "prompt is {} tokens, the context window is {limit}",
                prompt.len()
            )));
            return;
        }
        self.waiting.push_back(Waiting { job, prompt });
    }

    /// Starts waiting jobs, oldest first, while slots and KV cells allow.
    fn admit_waiting(&mut self) {
        while let Some(waiting) = self.waiting.pop_front() {
            if let Err(waiting) = self.admit(waiting) {
                self.waiting.push_front(waiting);
                return;
            }
        }
    }

    /// Err gives the job back when it has to wait for running requests.
    fn admit(&mut self, waiting: Waiting) -> Result<(), Waiting> {
        let Waiting { job, prompt } = waiting;
        let free: Vec<usize> = (0..self.slots.len()).filter(|&i| self.slots[i].is_none()).collect();
        if free.is_empty() {
            return Err(Waiting { job, prompt });
        }
        let reuse = self.reuse && job.params.reuse_prefix;
        // The free sequence sharing the longest beginning with this prompt;
        // without one, the one holding the least.
        let common = |cached: &[LlamaToken]| cached.iter().zip(&prompt).take_while(|(a, b)| a == b).count();
        let index = if reuse {
            *free.iter().max_by_key(|&&i| (common(&self.cached[i]), usize::MAX - self.cached[i].len())).unwrap_or(&free[0])
        } else {
            *free.iter().min_by_key(|&&i| self.cached[i].len()).unwrap_or(&free[0])
        };
        let mut keep = if reuse { common(&self.cached[index]).min(prompt.len() - 1) } else { 0 };
        let limit = self.ctx_per_request as usize;
        let max_tokens = (job.params.max_tokens as usize).min(limit - prompt.len());
        let need = prompt.len() - keep + max_tokens;

        // Room in the shared cache: drop other idle sequences, oldest first.
        loop {
            let held: usize = (0..self.slots.len())
                .map(|i| match &self.slots[i] {
                    _ if i == index => keep,
                    Some(slot) => self.cached[i].len() + slot.remaining(),
                    None => self.cached[i].len(),
                })
                .sum();
            if held + need <= self.kv_size {
                break;
            }
            let oldest = (0..self.slots.len())
                .filter(|&i| i != index && self.slots[i].is_none() && !self.cached[i].is_empty())
                .min_by_key(|&i| self.idle_since[i]);
            match oldest {
                Some(i) => self.forget(i),
                None => return Err(Waiting { job, prompt }),
            }
        }

        self.stats.queued.fetch_sub(1, Ordering::Relaxed);
        let seq = index as i32;
        if keep < self.cached[index].len() {
            let trimmed = keep > 0 && matches!(self.ctx.clear_kv_cache_seq(Some(seq as u32), Some(keep as u32), None), Ok(true));
            if !trimmed {
                self.forget(index);
                keep = 0;
            }
            self.cached[index].truncate(keep);
        }
        self.slots[index] = Some(Slot {
            seq,
            sampler: sampler(self.model, &job.params),
            filter: StopFilter::new(job.params.stop),
            special: job.params.special,
            tx: job.tx,
            bytes: Vec::new(),
            prompt,
            prefilled: keep,
            reused: keep,
            n_past: keep as i32,
            completion_tokens: 0,
            max_tokens,
            next: None,
            logits_at: None,
            admitted: Instant::now(),
            first_token: None,
        });
        Ok(())
    }

    /// Empties a sequence's KV cache.
    fn forget(&mut self, index: usize) {
        let _ = self.ctx.clear_kv_cache_seq(Some(index as u32), None, None);
        self.cached[index].clear();
    }

    /// One llama_decode: the next token of every generating request, plus
    /// as much pending prompt as still fits in the batch.
    fn step(&mut self, batch: &mut LlamaBatch) -> Result<(), String> {
        batch.clear();
        for (index, slot) in self.slots.iter_mut().enumerate() {
            let Some(slot) = slot else { continue };
            slot.logits_at = None;
            if let Some(token) = slot.next.take() {
                slot.logits_at = Some(batch.n_tokens());
                batch.add(token, slot.n_past, &[slot.seq], true).map_err(|e| e.to_string())?;
                self.cached[index].push(token);
                slot.n_past += 1;
            }
        }
        for (index, slot) in self.slots.iter_mut().enumerate() {
            let Some(slot) = slot else { continue };
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
                let token = slot.prompt[slot.prefilled];
                batch.add(token, slot.n_past, &[slot.seq], last).map_err(|e| e.to_string())?;
                self.cached[index].push(token);
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
            slot.first_token.get_or_insert_with(Instant::now);

            let finish = if vocab.is_eog(token) {
                Some("stop")
            } else {
                slot.completion_tokens += 1;
                // A token can end mid-character (Bengali, emoji); hold the
                // bytes until the character is complete.
                slot.bytes.extend(vocab.token_to_piece(token, slot.special, None));
                let text = take_utf8(&mut slot.bytes);
                let (emit, stopped) = slot.filter.push(&text);
                if !emit.is_empty() && slot.tx.send(GenEvent::Text(emit)).is_err() {
                    self.release(index, false); // client went away
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
                let (prompt_tps, gen_tps) = measure(slot);
                self.stats.record_speed(prompt_tps, gen_tps);
                self.release(index, true);
            }
        }
        Ok(())
    }

    /// Frees the slot; its KV cache stays for the next request when `keep`.
    fn release(&mut self, index: usize, keep: bool) {
        if self.slots[index].take().is_some() {
            self.idle_since[index] = Instant::now();
            if !(keep && self.reuse) {
                self.forget(index);
            }
        }
    }

    fn fail_all(&mut self, error: &str) {
        for index in 0..self.slots.len() {
            if let Some(slot) = self.slots[index].as_ref() {
                let _ = slot.tx.send(GenEvent::Error(error.to_string()));
            }
            self.release(index, false);
            self.forget(index);
        }
    }
}

/// Prompt reading speed (admitted → first token) and writing speed (first
/// → last token). Tiny prompts and replies say little, so they're skipped.
fn measure(slot: &Slot) -> (Option<f64>, Option<f64>) {
    let Some(first) = slot.first_token else { return (None, None) };
    let reading = first.duration_since(slot.admitted).as_secs_f64();
    let writing = first.elapsed().as_secs_f64();
    let read = slot.prompt.len() - slot.reused;
    let prompt_tps = (read >= 32 && reading > 0.0).then(|| read as f64 / reading);
    let gen_tps =
        (slot.completion_tokens >= 8 && writing > 0.0).then(|| (slot.completion_tokens - 1) as f64 / writing);
    (prompt_tps, gen_tps)
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
