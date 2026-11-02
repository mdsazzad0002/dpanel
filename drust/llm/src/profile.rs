//! What drust-llm has learned about each model on this machine, kept in
//! `<id>.profile.json` next to the GGUF file: what the model is for (read
//! from its own metadata on first load), how fast it really runs (from
//! every chat), and the GPU setting the self-test found fastest.

use llama_cpp_2::{LlamaBackendDeviceType, list_llama_ggml_backend_devices, model::LlamaModel};
use serde::{Deserialize, Serialize};
use std::{
    path::{Path, PathBuf},
    time::{SystemTime, UNIX_EPOCH},
};

#[derive(Clone, Default, Serialize, Deserialize)]
pub struct Profile {
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub detected: Option<Detected>,
    /// Chosen by the self-test; None = the server-wide default.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub gpu_layers: Option<u32>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub tune: Option<TuneResult>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub speed: Option<Speed>,
}

#[derive(Clone, Serialize, Deserialize)]
pub struct Detected {
    pub name: String,
    pub arch: String,
    pub n_params: u64,
    pub n_layer: u32,
    pub n_ctx_train: u32,
    pub chat_template: bool,
    /// In the AI Gateway's vocabulary: "chat", plus "code" for code models.
    pub capabilities: Vec<String>,
    /// Writes its reasoning (<think>…</think>) before answering.
    pub thinks: bool,
}

/// Measured on real requests, smoothed so one odd request doesn't swing it.
#[derive(Clone, Copy, Serialize, Deserialize)]
pub struct Speed {
    /// Prompt tokens read per second (how long until the first word).
    pub prompt_tps: f64,
    /// Tokens written per second.
    pub gen_tps: f64,
    pub samples: u64,
    pub updated: u64,
}

#[derive(Clone, Serialize, Deserialize)]
pub struct TuneResult {
    pub at: u64,
    pub device: Option<String>,
    pub trials: Vec<Trial>,
    pub chosen: u32,
}

#[derive(Clone, Serialize, Deserialize)]
pub struct Trial {
    pub gpu_layers: u32,
    pub prompt_tps: f64,
    pub gen_tps: f64,
    pub est_secs: f64,
}

pub fn path(dir: &Path, id: &str) -> PathBuf {
    dir.join(format!("{id}.profile.json"))
}

pub fn load(dir: &Path, id: &str) -> Profile {
    std::fs::read(path(dir, id))
        .ok()
        .and_then(|bytes| serde_json::from_slice(&bytes).ok())
        .unwrap_or_default()
}

/// Reads, changes and writes back the profile (through a temp file, so a
/// crash never leaves half a file).
pub fn update(dir: &Path, id: &str, change: impl FnOnce(&mut Profile)) {
    let mut profile = load(dir, id);
    change(&mut profile);
    let target = path(dir, id);
    let temp = target.with_extension("json.tmp");
    let written = serde_json::to_vec_pretty(&profile)
        .map_err(|error| error.to_string())
        .and_then(|bytes| std::fs::write(&temp, bytes).map_err(|error| error.to_string()))
        .and_then(|()| std::fs::rename(&temp, &target).map_err(|error| error.to_string()));
    if let Err(error) = written {
        tracing::warn!(model = %id, "cannot save profile: {error}");
    }
}

pub fn unix_now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_secs())
}

impl Speed {
    pub fn record(current: &mut Option<Speed>, prompt_tps: Option<f64>, gen_tps: Option<f64>) {
        let blend = |old: f64, new: Option<f64>| match new {
            Some(new) if old > 0.0 => old * 0.7 + new * 0.3,
            Some(new) => new,
            None => old,
        };
        let speed = current.get_or_insert(Speed { prompt_tps: 0.0, gen_tps: 0.0, samples: 0, updated: 0 });
        speed.prompt_tps = blend(speed.prompt_tps, prompt_tps);
        speed.gen_tps = blend(speed.gen_tps, gen_tps);
        speed.samples += 1;
        speed.updated = unix_now();
    }
}

/// Seconds a typical panel chat takes: the assistant's long system prompt
/// plus a medium reply. Lower is better; used to rank settings and models.
pub fn estimate_secs(prompt_tps: f64, gen_tps: f64) -> f64 {
    if prompt_tps <= 0.0 || gen_tps <= 0.0 {
        return f64::INFINITY;
    }
    600.0 / prompt_tps + 200.0 / gen_tps
}

/// Reads what the model is for from its own GGUF metadata and template.
pub fn detect(model: &LlamaModel, id: &str) -> Detected {
    let meta = |key: &str| model.meta_val_str(key).unwrap_or_default();
    let name = Some(meta("general.name")).filter(|name| !name.is_empty()).unwrap_or_else(|| id.to_string());
    let template = model.chat_template(None).ok().and_then(|t| t.to_string().ok());
    let haystack = format!(
        "{id} {name} {} {} {}",
        meta("general.basename"),
        meta("general.finetune"),
        meta("general.tags")
    )
    .to_lowercase();
    let has = |words: &[&str]| words.iter().any(|word| haystack.contains(word));

    let mut capabilities = vec!["chat".to_string()];
    if has(&["coder", "codestral", "devstral", "starcoder", "codellama", "codegemma", "codeqwen", "code-"]) {
        capabilities.push("code".to_string());
    }
    let thinks = template.as_deref().is_some_and(|t| t.contains("<think>"))
        || has(&["-r1", "r1-", "qwq", "reasoning", "thinking"]);

    Detected {
        name,
        arch: meta("general.architecture"),
        n_params: model.n_params(),
        n_layer: model.n_layer(),
        n_ctx_train: model.n_ctx_train(),
        chat_template: template.is_some(),
        capabilities,
        thinks,
    }
}

#[derive(Clone, Serialize)]
pub struct GpuDevice {
    pub name: String,
    pub description: String,
    pub backend: String,
    pub integrated: bool,
    pub memory_total: u64,
    pub memory_free: u64,
}

/// GPUs llama.cpp can use in this build; empty on CPU-only builds or
/// machines without one.
pub fn gpus() -> Vec<GpuDevice> {
    list_llama_ggml_backend_devices()
        .into_iter()
        .filter(|d| matches!(d.device_type, LlamaBackendDeviceType::Gpu | LlamaBackendDeviceType::IntegratedGpu))
        .map(|d| GpuDevice {
            integrated: matches!(d.device_type, LlamaBackendDeviceType::IntegratedGpu),
            name: d.name,
            description: d.description,
            backend: d.backend,
            memory_total: d.memory_total as u64,
            memory_free: d.memory_free as u64,
        })
        .collect()
}
