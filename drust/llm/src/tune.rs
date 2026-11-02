//! Self-test: loads a model with each GPU setting worth trying on this
//! machine (all layers on the GPU, half, none), times a fixed prompt and
//! reply, and keeps the setting that answers a typical chat fastest. Runs by
//! itself the first time a model is seen, and again from the panel's Test
//! button.

use crate::{
    AppState,
    infer::{Engine, GenEvent, GenParams, Job},
    profile::{self, Detected, Speed, Trial, TuneResult},
    registry::{model_path, start_engine},
};
use serde::Serialize;
use std::{collections::HashMap, path::Path, sync::Arc, sync::atomic::Ordering, time::Duration};
use tokio::sync::{Mutex, mpsc};

#[derive(Clone, Serialize)]
pub struct TuneStatus {
    pub state: &'static str, // queued | running | done | failed
    pub step: String,
    pub error: Option<String>,
}

pub type Tunes = Mutex<HashMap<String, TuneStatus>>;

/// Queues a self-test; they run one at a time (`AppState::tune_lock`)
/// because they compete for the same cores and GPU.
pub async fn start(state: Arc<AppState>, id: String) -> Result<(), String> {
    {
        let mut tunes = state.tunes.lock().await;
        if tunes.get(&id).is_some_and(|t| matches!(t.state, "queued" | "running")) {
            return Err("this model is already being tested".to_string());
        }
        tunes.insert(id.clone(), TuneStatus { state: "queued", step: "Waiting for its turn".into(), error: None });
    }
    tokio::spawn(async move {
        let result = run(&state, &id).await;
        state.registry.set_tuning(&id, false);
        if let Some(tune) = state.tunes.lock().await.get_mut(&id) {
            match result {
                Ok(step) => {
                    tune.state = "done";
                    tune.step = step;
                }
                Err(error) => {
                    tracing::warn!(model = %id, "self-test failed: {error}");
                    tune.state = "failed";
                    tune.error = Some(error);
                }
            }
        }
    });
    Ok(())
}

/// Tests every installed model that has never been tested, shortly after
/// startup (so the first time on a new machine it finds its own settings).
pub fn spawn_first_runs(state: Arc<AppState>) {
    tokio::spawn(async move {
        tokio::time::sleep(Duration::from_secs(20)).await;
        for model in state.registry.list(&state.config.models_dir).await {
            if model.profile.tune.is_none() {
                let _ = start(state.clone(), model.id).await;
            }
        }
    });
}

async fn set_step(state: &AppState, id: &str, step: String) {
    if let Some(tune) = state.tunes.lock().await.get_mut(id) {
        tune.state = "running";
        tune.step = step;
    }
}

async fn run(state: &Arc<AppState>, id: &str) -> Result<String, String> {
    let _turn = state.tune_lock.lock().await;
    let dir = &state.config.models_dir;
    let path = model_path(dir, id);
    if !path.exists() {
        return Err("model not found".to_string());
    }
    // Frees its memory for the test and keeps chats from loading it meanwhile.
    state.registry.set_tuning(id, true);
    state.registry.unload(dir, id).await;

    let gpus = profile::gpus();
    let mut options: Vec<u32> = match state.config.gpu_layers {
        Some(layers) => vec![layers],
        None if gpus.is_empty() => vec![0],
        None => vec![999, 0],
    };
    let mut trials = Vec::new();
    let mut detected: Option<Detected> = None;
    let mut index = 0;
    while index < options.len() {
        let layers = options[index];
        index += 1;
        let label = match layers {
            0 => "CPU only".to_string(),
            999 => "all layers on the GPU".to_string(),
            n => format!("{n} layers on the GPU"),
        };
        set_step(state, id, format!("Testing {label}")).await;
        match trial(state, id, &path, layers).await {
            Ok((result, found)) => {
                // Half the layers is worth a try once the layer count is known.
                if detected.is_none() && options.len() > 1 && found.n_layer > 1 {
                    options.insert(index, found.n_layer / 2);
                }
                detected.get_or_insert(found);
                trials.push(result);
            }
            Err(error) => tracing::warn!(model = %id, gpu_layers = layers, "trial failed: {error}"),
        }
    }

    let Some(best) = trials.iter().min_by(|a, b| a.est_secs.total_cmp(&b.est_secs)).cloned() else {
        return Err("every test run failed; see the drust-llm log".to_string());
    };
    let choose = state.config.gpu_layers.is_none() && !gpus.is_empty();
    profile::update(dir, id, |p| {
        p.detected = detected.clone();
        p.gpu_layers = choose.then_some(best.gpu_layers);
        p.tune = Some(TuneResult {
            at: profile::unix_now(),
            device: gpus.first().map(|g| g.description.clone()),
            trials: trials.clone(),
            chosen: best.gpu_layers,
        });
        // Until real chats teach it better, the test's numbers stand in.
        if p.speed.is_none() {
            p.speed = Some(Speed {
                prompt_tps: best.prompt_tps,
                gen_tps: best.gen_tps,
                samples: 0,
                updated: profile::unix_now(),
            });
        }
    });
    Ok(format!(
        "Best: {} (~{:.0}s for a typical chat)",
        match best.gpu_layers {
            0 => "CPU".to_string(),
            999 => "GPU".to_string(),
            n => format!("{n} layers on GPU"),
        },
        best.est_secs
    ))
}

async fn trial(state: &AppState, id: &str, path: &Path, gpu_layers: u32) -> Result<(Trial, Detected), String> {
    let engine = start_engine(state, path.to_path_buf(), gpu_layers).await?;
    // The first run compiles GPU shaders and warms caches; not measured
    // (too short to count as a sample anyway).
    run_job(&engine, "Hello", 4).await?;
    run_job(&engine, &bench_prompt(), 64).await?;
    let speed = (*engine.stats.speed.lock().unwrap_or_else(|e| e.into_inner()))
        .ok_or("the test reply was too short to measure")?;
    let detected = profile::detect(&engine.model, id);
    Ok((
        Trial {
            gpu_layers,
            prompt_tps: speed.prompt_tps,
            gen_tps: speed.gen_tps,
            est_secs: profile::estimate_secs(speed.prompt_tps, speed.gen_tps),
        },
        detected,
    ))
}

async fn run_job(engine: &Engine, prompt: &str, max_tokens: u32) -> Result<(), String> {
    let (tx, mut rx) = mpsc::unbounded_channel();
    let params = GenParams {
        max_tokens,
        temperature: 0.0,
        top_p: 1.0,
        frequency_penalty: 0.0,
        presence_penalty: 0.0,
        seed: 0,
        stop: Vec::new(),
    };
    engine.stats.queued.fetch_add(1, Ordering::Relaxed);
    engine
        .jobs
        .send(Job { prompt: prompt.to_string(), params, tx })
        .map_err(|_| "model worker stopped".to_string())?;
    while let Some(event) = rx.recv().await {
        match event {
            GenEvent::Text(_) => {}
            GenEvent::Done { .. } => return Ok(()),
            GenEvent::Error(error) => return Err(error),
        }
    }
    Err("model worker stopped".to_string())
}

/// About 500 tokens to read, like the assistant's system prompt, then a
/// reply that keeps going (counting) so the writing speed can be timed.
fn bench_prompt() -> String {
    let line = "The web server handled requests for several sites, rotated its logs, renewed a certificate and reported normal memory use. ";
    format!("{}\nCount from 1 to 200, separated by commas: 1, 2, 3,", line.repeat(20))
}
