use crate::{
    AppState,
    infer::{self, EngineConfig, Job, Stats},
    profile::{self, Profile},
};
use llama_cpp_2::{llama_backend::LlamaBackend, model::LlamaModel};
use serde::Serialize;
use std::{
    collections::{HashMap, HashSet},
    path::{Path, PathBuf},
    sync::{Arc, OnceLock, atomic::Ordering, mpsc as std_mpsc},
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};
use tokio::sync::Mutex;

static BACKEND: OnceLock<LlamaBackend> = OnceLock::new();

/// llama.cpp's process-wide backend, set up once in `Registry::new`.
pub fn backend() -> &'static LlamaBackend {
    BACKEND.get().expect("llama backend not initialised")
}

pub struct LoadedModel {
    pub id: String,
    pub model: Arc<LlamaModel>,
    /// Dropping the last LoadedModel closes this and stops the worker,
    /// which frees the model's memory.
    jobs: std_mpsc::Sender<Job>,
    pub stats: Arc<Stats>,
    pub ctx_per_request: u32,
    /// Layers this load actually put on the GPU.
    pub gpu_layers: u32,
}

impl LoadedModel {
    pub fn submit(&self, job: Job) -> Result<(), String> {
        self.stats.queued.fetch_add(1, Ordering::Relaxed);
        self.jobs.send(job).map_err(|_| {
            self.stats.queued.fetch_sub(1, Ordering::Relaxed);
            "model worker has stopped".to_string()
        })
    }
}

struct Slot {
    model: Arc<LoadedModel>,
    last_used: Instant,
}

#[derive(Serialize)]
pub struct ModelInfo {
    pub id: String,
    pub size_bytes: u64,
    pub created: u64,
    pub loaded: bool,
    /// Detected purpose, learned speed and self-test result.
    pub profile: Profile,
}

pub struct Registry {
    // A tokio lock held across a load, so two requests for the same cold
    // model load it once instead of twice.
    slots: Mutex<HashMap<String, Slot>>,
    /// Models the self-test is running; they aren't loaded for chats meanwhile.
    tuning: std::sync::Mutex<HashSet<String>>,
}

/// GPU layers for a load: DRUST_LLM_GPU_LAYERS when set, else what the
/// self-test chose for this model, else everything on a GPU if there is one.
pub fn gpu_layers_for(state: &AppState, profile: &Profile) -> u32 {
    state
        .config
        .gpu_layers
        .or(profile.gpu_layers)
        .unwrap_or(if profile::gpus().is_empty() { 0 } else { 999 })
}

/// Model ids are file names under the models dir; anything that could step
/// out of it is refused.
pub fn valid_id(id: &str) -> bool {
    !id.is_empty()
        && id.len() <= 128
        && !id.starts_with('.')
        && id.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '-'))
}

pub fn model_path(dir: &Path, id: &str) -> PathBuf {
    dir.join(format!("{id}.gguf"))
}

impl Registry {
    pub fn new() -> Result<Self, String> {
        let mut backend =
            LlamaBackend::init().map_err(|error| format!("llama.cpp init failed: {error}"))?;
        backend.void_logs();
        let _ = BACKEND.set(backend);
        Ok(Self { slots: Mutex::new(HashMap::new()), tuning: Default::default() })
    }

    pub fn set_tuning(&self, id: &str, on: bool) -> bool {
        let mut tuning = self.tuning.lock().unwrap_or_else(|e| e.into_inner());
        if on { tuning.insert(id.to_string()) } else { tuning.remove(id) }
    }

    fn is_tuning(&self, id: &str) -> bool {
        self.tuning.lock().unwrap_or_else(|e| e.into_inner()).contains(id)
    }

    pub async fn loaded_ids(&self) -> Vec<String> {
        let mut ids: Vec<_> = self.slots.lock().await.keys().cloned().collect();
        ids.sort();
        ids
    }

    pub async fn list(&self, dir: &Path) -> Vec<ModelInfo> {
        let live: HashMap<String, Option<profile::Speed>> = self
            .slots
            .lock()
            .await
            .iter()
            .map(|(id, slot)| (id.clone(), *slot.model.stats.speed.lock().unwrap_or_else(|e| e.into_inner())))
            .collect();
        let mut models = Vec::new();
        let Ok(entries) = std::fs::read_dir(dir) else { return models };
        for entry in entries.flatten() {
            let path = entry.path();
            if path.extension().and_then(|ext| ext.to_str()) != Some("gguf") {
                continue;
            }
            let Some(id) = path.file_stem().and_then(|stem| stem.to_str()) else { continue };
            if !valid_id(id) {
                continue;
            }
            let meta = entry.metadata().ok();
            let mut profile = profile::load(dir, id);
            // A loaded model's speed may be newer than the saved one.
            if let Some(Some(speed)) = live.get(id) {
                profile.speed = Some(*speed);
            }
            models.push(ModelInfo {
                id: id.to_string(),
                size_bytes: meta.as_ref().map_or(0, |m| m.len()),
                created: meta
                    .and_then(|m| m.modified().ok())
                    .and_then(|t| t.duration_since(UNIX_EPOCH).ok())
                    .map_or(0, |d| d.as_secs()),
                loaded: live.contains_key(id),
                profile,
            });
        }
        models.sort_by(|a, b| a.id.cmp(&b.id));
        models
    }

    /// The loaded model, loading it first (and evicting the least recently
    /// used one past `max_loaded`) when it isn't in memory yet.
    pub async fn get(self: &Arc<Self>, state: &AppState, id: &str) -> Result<Arc<LoadedModel>, String> {
        let mut slots = self.slots.lock().await;
        if let Some(slot) = slots.get_mut(id) {
            slot.last_used = Instant::now();
            return Ok(slot.model.clone());
        }

        if self.is_tuning(id) {
            return Err(format!("'{id}' is being speed-tested; try again in a minute"));
        }
        let path = model_path(&state.config.models_dir, id);
        let size = std::fs::metadata(&path)
            .map_err(|_| format!("model '{id}' not found"))?
            .len();

        while slots.len() >= state.config.max_loaded {
            let Some(oldest) = slots
                .iter()
                .min_by_key(|(_, slot)| slot.last_used)
                .map(|(id, _)| id.clone())
            else {
                break;
            };
            // A request still generating keeps its Arc; the memory goes when it ends.
            slots.remove(&oldest);
            tracing::info!(model = %oldest, "unloaded to make room");
        }

        // The weights are mmapped, so an oversized model "loads" fine and
        // then pages the whole server to a crawl. Refuse it up front.
        if let Some(available) = mem_available() {
            if size > available {
                return Err(format!(
                    "not enough memory for '{id}': needs ~{} MB, {} MB available",
                    size / 1_048_576,
                    available / 1_048_576
                ));
            }
        }

        let dir = &state.config.models_dir;
        let saved = profile::load(dir, id);
        let gpu_layers = gpu_layers_for(state, &saved);
        let started = Instant::now();
        let (engine, gpu_layers) = match start_engine(state, path.clone(), gpu_layers).await {
            Ok(engine) => (engine, gpu_layers),
            // A GPU without enough memory, or a driver problem: run on the CPU.
            Err(error) if gpu_layers > 0 => {
                tracing::warn!(model = %id, "GPU load failed, using the CPU: {error}");
                (start_engine(state, path, 0).await.map_err(|error| format!("'{id}': {error}"))?, 0)
            }
            Err(error) => return Err(format!("'{id}': {error}")),
        };
        tracing::info!(model = %id, gpu_layers, ms = started.elapsed().as_millis() as u64, "loaded");

        // First load (or a replaced file): read what the model is for.
        let detected = profile::detect(&engine.model, id);
        profile::update(dir, id, |p| p.detected = Some(detected));
        *engine.stats.speed.lock().unwrap_or_else(|e| e.into_inner()) = saved.speed;

        let loaded = Arc::new(LoadedModel {
            id: id.to_string(),
            model: engine.model,
            jobs: engine.jobs,
            stats: engine.stats,
            ctx_per_request: engine.ctx_per_request,
            gpu_layers,
        });
        slots.insert(id.to_string(), Slot { model: loaded.clone(), last_used: Instant::now() });
        Ok(loaded)
    }

    /// Every loaded model with its idle time and running/waiting requests.
    pub async fn loaded_detail(&self) -> Vec<serde_json::Value> {
        let slots = self.slots.lock().await;
        let mut ids: Vec<_> = slots.keys().collect();
        ids.sort();
        ids.into_iter()
            .map(|id| {
                let slot = &slots[id];
                let stats = &slot.model.stats;
                serde_json::json!({
                    "id": id,
                    "idle_seconds": slot.last_used.elapsed().as_secs(),
                    "active": stats.active.load(Ordering::Relaxed),
                    "queued": stats.queued.load(Ordering::Relaxed),
                    "ctx_per_request": slot.model.ctx_per_request,
                    "gpu_layers": slot.model.gpu_layers,
                })
            })
            .collect()
    }

    pub async fn touch(&self, id: &str) {
        if let Some(slot) = self.slots.lock().await.get_mut(id) {
            slot.last_used = Instant::now();
        }
    }

    pub async fn unload(&self, dir: &Path, id: &str) -> bool {
        let removed = self.slots.lock().await.remove(id);
        if let Some(slot) = &removed {
            save_speed(dir, id, slot);
        }
        removed.is_some()
    }

    /// Writes newly learned speeds into the profiles.
    pub async fn save_speeds(&self, dir: &Path) {
        for (id, slot) in self.slots.lock().await.iter() {
            save_speed(dir, id, slot);
        }
    }

    async fn unload_idle(&self, dir: &Path, idle: Duration) {
        let mut slots = self.slots.lock().await;
        slots.retain(|id, slot| {
            let stats = &slot.model.stats;
            let busy = stats.active.load(Ordering::Relaxed) + stats.queued.load(Ordering::Relaxed) > 0;
            let keep = busy || slot.last_used.elapsed() < idle;
            if !keep {
                save_speed(dir, id, slot);
                tracing::info!(model = %id, "unloaded after idle timeout");
            }
            keep
        });
    }
}

fn save_speed(dir: &Path, id: &str, slot: &Slot) {
    let stats = &slot.model.stats;
    if stats.speed_dirty.swap(false, Ordering::Relaxed) {
        let speed = *stats.speed.lock().unwrap_or_else(|e| e.into_inner());
        profile::update(dir, id, |p| p.speed = speed);
    }
}

pub async fn start_engine(state: &AppState, path: PathBuf, gpu_layers: u32) -> Result<infer::Engine, String> {
    let config = EngineConfig {
        ctx_size: state.config.ctx_size,
        kv_size: state.config.kv_size,
        parallel: state.config.parallel,
        threads: state.config.threads,
        gpu_layers,
    };
    tokio::task::spawn_blocking(move || infer::start(path, config))
        .await
        .map_err(|error| format!("model loader crashed: {error}"))?
}

/// Every 30s: unload idle models and save learned speeds.
pub fn spawn_idle_reaper(state: Arc<AppState>) {
    let idle = state.config.idle_unload;
    tokio::spawn(async move {
        let mut tick = tokio::time::interval(Duration::from_secs(30));
        loop {
            tick.tick().await;
            let dir = &state.config.models_dir;
            if !idle.is_zero() {
                state.registry.unload_idle(dir, idle).await;
            }
            state.registry.save_speeds(dir).await;
        }
    });
}

fn mem_available() -> Option<u64> {
    meminfo("MemAvailable:")
}

pub fn meminfo(key: &str) -> Option<u64> {
    let meminfo = std::fs::read_to_string("/proc/meminfo").ok()?;
    let line = meminfo.lines().find(|line| line.starts_with(key))?;
    let kb: u64 = line.split_whitespace().nth(1)?.parse().ok()?;
    Some(kb * 1024)
}

pub fn unix_now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_secs())
}
