//! mongo-express on the panel domain at /mongo-express/. It runs as its own
//! service (dpanel-mongo-express, installed by the dscript mongodb module) with
//! ME_CONFIG_SITE_BASEURL=/mongo-express/, so the path is forwarded unchanged.
//! Sign-on: see crate::mongo_express_sso.

use std::net::SocketAddr;

use axum::{
    body::Body,
    http::{HeaderValue, Request, StatusCode, header},
    response::Response,
};

use crate::mongo_express_sso::{self, BASE_PATH, SESSION_COOKIE, SESSION_TTL, SSO_PATH, UPSTREAM};

use super::{annotated_response, pgadmin_notice_response, proxy_request, redirect_response};

pub(super) fn is_path(path: &str) -> bool {
    path == BASE_PATH || path.starts_with("/mongo-express/")
}

pub(super) async fn handle(
    mut request: Request<Body>,
    path: &str,
    proxy_client: &reqwest::Client,
    site_match: &str,
) -> Response {
    // The gateway adds mongo-express's login itself; a browser must never be
    // able to send one.
    request.headers_mut().remove(header::AUTHORIZATION);

    // `path` is normalized (no trailing slash); check the raw one so
    // "/mongo-express/" doesn't redirect to itself.
    if path == BASE_PATH && request.uri().path() == BASE_PATH {
        return annotated_response(redirect_response(301, "/mongo-express/"), site_match, BASE_PATH);
    }
    if path == SSO_PATH {
        return annotated_response(sign_in(&request), site_match, BASE_PATH);
    }

    let cookies = request
        .headers()
        .get_all(header::COOKIE)
        .iter()
        .filter_map(|value| value.to_str().ok());
    if !mongo_express_sso::has_session(cookies) {
        return annotated_response(
            pgadmin_notice_response(
                StatusCode::FORBIDDEN,
                "Open mongo-express from dPanel",
                "mongo-express signs you in through dPanel. Use <strong>Open mongo-express</strong> on Database Management &rarr; MongoDB.",
            ),
            site_match,
            BASE_PATH,
        );
    }
    let Some(login) = mongo_express_sso::basic_auth_header().and_then(|value| HeaderValue::from_str(&value).ok()) else {
        return annotated_response(
            pgadmin_notice_response(
                StatusCode::SERVICE_UNAVAILABLE,
                "mongo-express is not installed",
                "Install it on the server with <code>sudo dpanel mongodb install</code>.",
            ),
            site_match,
            BASE_PATH,
        );
    };
    request.headers_mut().insert(header::AUTHORIZATION, login);

    let upstream = crate::edge_gateway::UpstreamConfig::Http(SocketAddr::from(UPSTREAM));
    let mut response = match proxy_request(proxy_client, &upstream, request).await {
        Ok(response) => response,
        // Off by default; a refused connection means nobody turned it on.
        Err(_) => unavailable(),
    };
    // Never let the browser show its own basic-auth prompt.
    response.headers_mut().remove(header::WWW_AUTHENTICATE);
    annotated_response(response, site_match, BASE_PATH)
}

/// Turns a one-time dPanel ticket into a session cookie and opens mongo-express.
fn sign_in(request: &Request<Body>) -> Response {
    let ticket = request
        .uri()
        .query()
        .unwrap_or("")
        .split('&')
        .find_map(|pair| pair.strip_prefix("ticket="))
        .unwrap_or("");
    let Some(session) = mongo_express_sso::redeem(ticket) else {
        return pgadmin_notice_response(
            StatusCode::FORBIDDEN,
            "mongo-express link expired",
            "This sign-in link has already been used or is older than a minute. Open mongo-express again from dPanel.",
        );
    };
    let https = request
        .headers()
        .get("x-forwarded-proto")
        .is_some_and(|value| value.as_bytes().eq_ignore_ascii_case(b"https"));
    let cookie = format!(
        "{SESSION_COOKIE}={session}; Path=/mongo-express/; Max-Age={}; HttpOnly; SameSite=Lax{}",
        SESSION_TTL.as_secs(),
        if https { "; Secure" } else { "" }
    );
    let mut response = redirect_response(302, "/mongo-express/");
    if let Ok(value) = HeaderValue::from_str(&cookie) {
        response.headers_mut().insert(header::SET_COOKIE, value);
    }
    response
        .headers_mut()
        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response
}

fn unavailable() -> Response {
    pgadmin_notice_response(
        StatusCode::SERVICE_UNAVAILABLE,
        "mongo-express is turned off",
        "MongoDB and mongo-express are installed but not running. An admin can turn them on from <strong>dPanel &rarr; Database Management &rarr; MongoDB</strong>, or on the server with <code>sudo dpanel mongodb start</code>.",
    )
}
