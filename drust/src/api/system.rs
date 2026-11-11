//! `GET /system/overview` (backlog D3): the dashboard's and monitoring
//! page's machine numbers and service states, from `platform::metrics`.
//! `GET /system/addresses` (U5): the machine's IP addresses for the IP pool.

use std::sync::Arc;

use axum::{
    Json, Router,
    extract::{Query, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::get,
};
use serde::Deserialize;

use super::{ApiResponse, ApiState, check_token};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/system/overview", get(overview))
        .route("/system/services", get(services))
        .route("/system/addresses", get(addresses))
}

/// `501` where the OS has no address listing yet, so the panel can say so.
async fn addresses(State(state): State<Arc<ApiState>>, headers: HeaderMap) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(|| address_report(&crate::platform::network::SystemAddresses)).await {
        Ok(Ok(addresses)) => Json(serde_json::json!({ "success": true, "addresses": addresses })).into_response(),
        Ok(Err(error)) => error.into_response(),
        Err(error) => ApiResponse::error(&format!("Address listing stopped: {error}")).into_response(),
    }
}

fn address_report(
    source: &dyn crate::platform::network::AddressSource,
) -> Result<Vec<crate::platform::network::ServerAddress>, crate::platform::PlatformError> {
    source.addresses().map(crate::platform::network::usable)
}

fn names(list: &str) -> Vec<String> {
    list.split(',').map(str::trim).filter(|name| !name.is_empty()).take(32).map(str::to_string).collect()
}

/// Each named unit's full state; a name the service manager would read as
/// an option, or one it does not know, comes back as not installed.
async fn services(State(state): State<Arc<ApiState>>, headers: HeaderMap, Query(query): Query<OverviewQuery>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let names = names(&query.services);
    let result = tokio::task::spawn_blocking(move || {
        let manager = crate::platform::services();
        names
            .into_iter()
            .map(|name| {
                let status = crate::platform::metrics::valid_service_name(&name).then(|| manager.status(&name).ok()).flatten();
                let value = match status {
                    Some(status) => serde_json::json!({ "installed": status.installed, "active": status.active, "enabled": status.enabled, "state": status.state }),
                    None => serde_json::json!({ "installed": false, "active": false, "enabled": false, "state": "unknown" }),
                };
                (name, value)
            })
            .collect::<serde_json::Map<_, _>>()
    })
    .await;
    match result {
        Ok(services) => Json(serde_json::json!({ "success": true, "services": services })).into_response(),
        Err(error) => ApiResponse::error(&format!("Service check stopped: {error}")).into_response(),
    }
}

#[derive(Deserialize)]
struct OverviewQuery {
    /// Comma-separated unit names.
    #[serde(default)]
    services: String,
}

async fn overview(State(state): State<Arc<ApiState>>, headers: HeaderMap, Query(query): Query<OverviewQuery>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let services = names(&query.services);
    let disk_path = crate::platform::paths().panel_root().to_path_buf();
    match tokio::task::spawn_blocking(move || crate::platform::metrics::overview(&services, &disk_path)).await {
        Ok(overview) => Json(serde_json::json!({ "success": true, "overview": overview })).into_response(),
        Err(error) => ApiResponse::error(&format!("System overview stopped: {error}")).into_response(),
    }
}

#[cfg(test)]
mod tests {
    use super::address_report;
    use crate::platform::{
        PlatformError,
        capabilities::Capability,
        network::{AddressSource, InterfaceAddress},
    };

    struct FakeAddresses(Result<Vec<InterfaceAddress>, PlatformError>);

    impl AddressSource for FakeAddresses {
        fn addresses(&self) -> Result<Vec<InterfaceAddress>, PlatformError> {
            self.0.clone()
        }
    }

    #[test]
    fn the_report_lists_usable_addresses_and_passes_unsupported_on() {
        let fake = FakeAddresses(Ok(vec![
            InterfaceAddress { interface: "lo".into(), address: "127.0.0.1".parse().unwrap(), prefix: 8 },
            InterfaceAddress { interface: "ens3".into(), address: "10.0.0.5".parse().unwrap(), prefix: 24 },
        ]));
        let report = address_report(&fake).unwrap();
        assert_eq!(report.len(), 1);
        assert_eq!(serde_json::to_value(&report[0]).unwrap(), serde_json::json!({"interface": "ens3", "address": "10.0.0.5", "prefix": 24, "family": "ipv4", "public": false}));

        let unsupported = FakeAddresses(Err(PlatformError::Unsupported { capability: Capability::IpAddresses }));
        assert_eq!(address_report(&unsupported), Err(PlatformError::Unsupported { capability: Capability::IpAddresses }));
    }
}
