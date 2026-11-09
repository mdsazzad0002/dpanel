//! The WAF engine (backlog S2, spike S1 in `plans/spikes/waf.md`):
//! libmodsecurity 3 with the OWASP CRS. The library is loaded when the
//! engine starts, not linked, so drust builds and runs without it and the
//! `waf` capability is simply off. The engine only ever runs inside a
//! `drust waf-worker` process, never in the gateway (`edge_gateway::waf`).

use std::path::{Path, PathBuf};

use super::PlatformError;

/// One request as the gateway saw it; the body may be cut short.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct WafRequest {
    pub method: String,
    pub uri: String,
    pub version: String,
    pub client_ip: String,
    pub client_port: u16,
    pub server_port: u16,
    pub headers: Vec<(String, String)>,
    pub body: Vec<u8>,
}

/// What the rules decided: `status` 200 lets the request through, anything
/// else is what blocking it would answer. `messages` are the rule matches.
#[derive(Debug, Clone, Default, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
pub struct Verdict {
    pub status: u16,
    pub messages: Vec<String>,
}

pub trait WafEngine: Send {
    fn inspect(&mut self, request: &WafRequest) -> Verdict;
}

/// The rules file the worker loads.
pub fn rules_file() -> PathBuf {
    super::paths().waf_dir().join("main.conf")
}

/// Whether a worker could start here: rules installed and the library present.
pub fn available() -> bool {
    rules_file().is_file() && library_present()
}

fn library_present() -> bool {
    #[cfg(unix)]
    {
        super::unix::modsecurity::library_present()
    }
    #[cfg(not(unix))]
    {
        false
    }
}

/// Loads the library and the rules. Slow (a few hundred milliseconds): once per worker.
pub fn load(rules: &Path) -> Result<Box<dyn WafEngine>, PlatformError> {
    #[cfg(unix)]
    {
        super::unix::modsecurity::ModSecurity::load(rules).map(|engine| Box::new(engine) as Box<dyn WafEngine>)
    }
    #[cfg(not(unix))]
    {
        let _ = rules;
        Err(PlatformError::Unsupported { capability: super::capabilities::Capability::Waf })
    }
}
