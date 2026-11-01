use crate::AppState;
use llama_cpp_2::{
    llama_backend::LlamaBackend,
    model::{LlamaModel, params::LlamaModelParams},
};
use serde::Serialize;
use std::{
    collections::HashMap,
    path::{Path, PathBuf},
    sync::Arc,
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};
use tokio::sync::Mutex;

pub struct LoadedModel {
    pub id: String,
    pub model: LlamaModel,
    /// llama.cpp can't build two contexts from one model at the same time
    /// (it mutates the model while doing so), and on a CPU box parallel
    /// generations only slow each other down, so a model runs one request
    /// at a time and the rest queue here.
    pub busy: Arc<Mutex<()>>,
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
}

pub struct Registry {
    backend: LlamaBackend,
    // A tokio lock held across a load, so two requests for the same cold
    // model load it once instead of twice.
    slots: Mutex<HashMap<String, Slot>>,
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
        Ok(Self { backend, slots: Mutex::new(HashMap::new()) })
    }

    pub async fn loaded_ids(&self) -> Vec<String> {
        let mut ids: Vec<_> = self.slots.lock().await.keys().cloned().collect();
        ids.sort();
        ids
    }

    pub async fn list(&self, dir: &Path) -> Vec<ModelInfo> {
        let loaded = self.loaded_ids().await;
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
            models.push(ModelInfo {
                id: id.to_string(),
                size_bytes: meta.as_ref().map_or(0, |m| m.len()),
                created: meta
                    .and_then(|m| m.modified().ok())
                    .and_then(|t| t.duration_since(UNIX_EPOCH).ok())
                    .map_or(0, |d| d.as_secs()),
                loaded: loaded.iter().any(|l| l == id),
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

        let registry = self.clone();
        let owned_id = id.to_string();
        let started = Instant::now();
        let model = tokio::task::spawn_blocking(move || {
            LlamaModel::load_from_file(&registry.backend, &path, &LlamaModelParams::default())
                .map_err(|error| format!("failed to load '{owned_id}': {error}"))
        })
        .await
        .map_err(|error| format!("model loader crashed: {error}"))??;
        tracing::info!(model = %id, ms = started.elapsed().as_millis() as u64, "loaded");

        let loaded = Arc::new(LoadedModel { id: id.to_string(), model, busy: Arc::new(Mutex::new(())) });
        slots.insert(id.to_string(), Slot { model: loaded.clone(), last_used: Instant::now() });
        Ok(loaded)
    }

    pub fn backend(&self) -> &LlamaBackend {
        &self.backend
    }

    /// (id, seconds since last use) for every loaded model.
    pub async fn loaded_detail(&self) -> Vec<(String, u64)> {
        let slots = self.slots.lock().await;
        let mut detail: Vec<_> =
            slots.iter().map(|(id, slot)| (id.clone(), slot.last_used.elapsed().as_secs())).collect();
        detail.sort();
        detail
    }

    pub async fn touch(&self, id: &str) {
        if let Some(slot) = self.slots.lock().await.get_mut(id) {
            slot.last_used = Instant::now();
        }
    }

    pub async fn unload(&self, id: &str) -> bool {
        self.slots.lock().await.remove(id).is_some()
    }

    async fn unload_idle(&self, idle: Duration) {
        let mut slots = self.slots.lock().await;
        slots.retain(|id, slot| {
            let keep = slot.last_used.elapsed() < idle;
            if !keep {
                tracing::info!(model = %id, "unloaded after idle timeout");
            }
            keep
        });
    }
}

pub fn spawn_idle_reaper(state: Arc<AppState>) {
    let idle = state.config.idle_unload;
    if idle.is_zero() {
        return;
    }
    tokio::spawn(async move {
        let mut tick = tokio::time::interval(Duration::from_secs(30));
        loop {
            tick.tick().await;
            state.registry.unload_idle(idle).await;
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
