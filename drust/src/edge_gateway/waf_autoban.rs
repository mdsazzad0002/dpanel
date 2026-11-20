//! Bans an IP at the firewall after the WAF blocked it many times.
//!
//! A site in `block` mode answers each attack with a 403, but the attacker
//! keeps sending, and every request still costs a WAF inspection. Once one
//! address is blocked [`hits`] times within [`WINDOW`], it is denied at the
//! server's firewall (UFW or firewalld) for [`ban_minutes`], so its traffic
//! never reaches the gateway. Bans are kept in `<state_dir>/waf-bans.json`
//! with their expiry and lifted by a sweep, also after a restart; the deny
//! rule shows in the panel's firewall list, where an admin can remove it.
//!
//! Never banned: loopback, private and link-local addresses, the server's own
//! addresses, the fail2ban whitelist, and any peer that forwards for someone
//! else (a CDN, a load balancer): banning it would take the site down for
//! everyone behind it. Linux only; elsewhere there is no firewall to use.

use std::{
    collections::{BTreeMap, HashMap, VecDeque},
    fs,
    net::{IpAddr, UdpSocket},
    path::PathBuf,
    sync::{Mutex, OnceLock},
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};

use axum::http::HeaderMap;
use tracing::{info, warn};

use crate::platform::{self, firewall::IpNet};

/// Blocks within this long count towards a ban.
const WINDOW: Duration = Duration::from_secs(600);
/// How often expired bans are lifted.
const SWEEP_EVERY: Duration = Duration::from_secs(60);
/// Addresses tracked at once; the oldest are dropped past this.
const MAX_TRACKED: usize = 50_000;

fn env_number(name: &str, default: u64, min: u64, max: u64) -> u64 {
    std::env::var(name)
        .ok()
        .and_then(|value| value.trim().parse::<u64>().ok())
        .unwrap_or(default)
        .clamp(min, max)
}

/// Blocks within [`WINDOW`] that earn a ban.
fn hits() -> usize {
    env_number("DRUST_WAF_AUTOBAN_HITS", 10, 3, 1000) as usize
}

/// How long a ban lasts.
fn ban_minutes() -> u64 {
    env_number("DRUST_WAF_AUTOBAN_MINUTES", 60, 5, 7 * 24 * 60)
}

fn enabled() -> bool {
    cfg!(target_os = "linux")
        && !matches!(
            std::env::var("DRUST_WAF_AUTOBAN").ok().as_deref().map(str::trim),
            Some("0" | "false" | "off" | "no")
        )
}

fn now_secs() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |since| since.as_secs())
}

#[derive(Default)]
struct State {
    blocks: HashMap<IpAddr, VecDeque<Instant>>,
    /// Banned address → when the ban ends, in seconds since 1970.
    banned: BTreeMap<IpAddr, u64>,
}

fn state() -> &'static Mutex<State> {
    static STATE: OnceLock<Mutex<State>> = OnceLock::new();
    STATE.get_or_init(|| Mutex::new(State { banned: read_store(), ..State::default() }))
}

fn store_path() -> PathBuf {
    platform::paths().state_dir().join("waf-bans.json")
}

fn read_store() -> BTreeMap<IpAddr, u64> {
    fs::read_to_string(store_path())
        .ok()
        .and_then(|content| serde_json::from_str(&content).ok())
        .unwrap_or_default()
}

fn write_store(banned: &BTreeMap<IpAddr, u64>) {
    let path = store_path();
    let result = (|| {
        if let Some(parent) = path.parent() {
            fs::create_dir_all(parent)?;
        }
        let temporary = path.with_extension("json.tmp");
        fs::write(&temporary, serde_json::to_string_pretty(banned).unwrap_or_default())?;
        fs::rename(&temporary, &path)
    })();
    if let Err(error) = result {
        warn!(path = %path.display(), %error, "cannot save WAF bans");
    }
}

/// Whether the request came through a proxy speaking for another address.
pub fn forwarded_for_someone_else(headers: &HeaderMap, peer: IpAddr) -> bool {
    let named = |name: &str| headers.get(name).and_then(|value| value.to_str().ok()).map(str::to_string);
    let mut claimed = Vec::new();
    for header in ["cf-connecting-ip", "x-real-ip", "true-client-ip"] {
        claimed.extend(named(header));
    }
    if let Some(chain) = named("x-forwarded-for") {
        claimed.extend(chain.split(',').map(|part| part.trim().to_string()));
    }
    claimed
        .iter()
        .any(|value| !value.is_empty() && value.parse::<IpAddr>().map_or(true, |ip| ip != peer))
}

/// Addresses that must never be banned, whatever they send.
fn never_ban(ip: IpAddr) -> bool {
    let reserved = match ip {
        IpAddr::V4(v4) => v4.is_loopback() || v4.is_private() || v4.is_link_local() || v4.is_unspecified() || v4.is_broadcast(),
        IpAddr::V6(v6) => {
            let first = v6.segments()[0];
            v6.is_loopback() || v6.is_unspecified() || (first & 0xfe00) == 0xfc00 || (first & 0xffc0) == 0xfe80
        }
    };
    reserved || is_own_address(ip) || whitelisted(ip)
}

/// Binding to an address works only when it belongs to this machine.
fn is_own_address(ip: IpAddr) -> bool {
    UdpSocket::bind((ip, 0)).is_ok()
}

fn whitelisted(ip: IpAddr) -> bool {
    crate::fail2ban::read_whitelist()
        .iter()
        .filter_map(|entry| entry.parse::<IpNet>().ok())
        .any(|net| net.contains(ip))
}

/// Adds one block at `now`; `true` when it earns `ip` a ban.
fn count_block(state: &mut State, ip: IpAddr, now: Instant, limit: usize) -> bool {
    if state.banned.contains_key(&ip) {
        return false;
    }
    if state.blocks.len() >= MAX_TRACKED && !state.blocks.contains_key(&ip) {
        state.blocks.retain(|_, times| times.back().is_some_and(|last| now.duration_since(*last) < WINDOW));
        if state.blocks.len() >= MAX_TRACKED {
            return false;
        }
    }
    let times = state.blocks.entry(ip).or_default();
    while times.front().is_some_and(|first| now.duration_since(*first) >= WINDOW) {
        times.pop_front();
    }
    times.push_back(now);
    if times.len() >= limit {
        state.blocks.remove(&ip);
        return true;
    }
    false
}

/// Called by the WAF each time it blocks a request from `peer`.
pub fn record_block(peer: IpAddr, headers: &HeaderMap) {
    if !enabled() || forwarded_for_someone_else(headers, peer) {
        return;
    }
    let earned = {
        let mut state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        count_block(&mut state, peer, Instant::now(), hits())
    };
    if earned {
        tokio::task::spawn_blocking(move || ban(peer));
    }
}

fn ban(ip: IpAddr) {
    if never_ban(ip) {
        return;
    }
    let minutes = ban_minutes();
    if let Err(error) = platform::firewall::backend().ban(ip, Some(Duration::from_secs(minutes * 60))) {
        warn!(%ip, %error, "WAF auto-ban: the firewall refused the ban");
        return;
    }
    info!(%ip, minutes, "WAF auto-ban: address banned at the firewall after repeated blocks");
    let mut state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    state.banned.insert(ip, now_secs() + minutes * 60);
    write_store(&state.banned);
}

/// Lifts the bans whose time is up.
fn sweep() {
    let now = now_secs();
    let expired: Vec<IpAddr> = {
        let state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        state.banned.iter().filter(|(_, until)| **until <= now).map(|(ip, _)| *ip).collect()
    };
    if expired.is_empty() {
        return;
    }
    let firewall = platform::firewall::backend();
    let mut lifted = Vec::new();
    for ip in expired {
        match firewall.unban(ip) {
            Ok(()) => lifted.push(ip),
            Err(error) => warn!(%ip, %error, "WAF auto-ban: cannot lift an expired ban; retrying later"),
        }
    }
    let mut state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    for ip in &lifted {
        state.banned.remove(ip);
    }
    write_store(&state.banned);
}

/// Starts lifting expired bans on the current Tokio runtime.
pub fn start() {
    if !enabled() {
        return;
    }
    tokio::spawn(async {
        let mut every = tokio::time::interval(SWEEP_EVERY);
        loop {
            every.tick().await;
            let _ = tokio::task::spawn_blocking(sweep).await;
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ip(text: &str) -> IpAddr {
        text.parse().unwrap()
    }

    #[test]
    fn bans_after_enough_blocks_within_the_window() {
        let mut state = State::default();
        let start = Instant::now();
        let attacker = ip("203.0.113.7");
        for second in 0..9 {
            assert!(!count_block(&mut state, attacker, start + Duration::from_secs(second), 10));
        }
        assert!(count_block(&mut state, attacker, start + Duration::from_secs(9), 10));
    }

    #[test]
    fn old_blocks_fall_out_of_the_window() {
        let mut state = State::default();
        let start = Instant::now();
        let visitor = ip("198.51.100.4");
        for minute in 0..20 {
            assert!(!count_block(&mut state, visitor, start + Duration::from_secs(minute * 120), 10));
        }
    }

    #[test]
    fn a_banned_address_is_not_counted_again() {
        let mut state = State::default();
        state.banned.insert(ip("203.0.113.7"), u64::MAX);
        assert!(!count_block(&mut state, ip("203.0.113.7"), Instant::now(), 1));
    }

    #[test]
    fn a_proxy_speaking_for_others_is_never_the_target() {
        let peer = ip("104.16.0.1");
        let mut headers = HeaderMap::new();
        assert!(!forwarded_for_someone_else(&headers, peer));
        headers.insert("cf-connecting-ip", "203.0.113.7".parse().unwrap());
        assert!(forwarded_for_someone_else(&headers, peer));

        let mut headers = HeaderMap::new();
        headers.insert("x-forwarded-for", "203.0.113.7, 104.16.0.1".parse().unwrap());
        assert!(forwarded_for_someone_else(&headers, peer));

        let mut headers = HeaderMap::new();
        headers.insert("x-forwarded-for", "104.16.0.1".parse().unwrap());
        assert!(!forwarded_for_someone_else(&headers, peer));
    }

    #[test]
    fn private_and_local_addresses_are_never_banned() {
        for address in ["127.0.0.1", "10.1.2.3", "192.168.1.9", "172.16.0.5", "169.254.1.1", "::1", "fd00::1", "fe80::1"] {
            assert!(never_ban(ip(address)), "{address}");
        }
    }

    #[test]
    fn networks_hold_their_addresses() {
        let net: IpNet = "203.0.113.0/24".parse().unwrap();
        assert!(net.contains(ip("203.0.113.200")));
        assert!(!net.contains(ip("203.0.114.1")));
        assert!(!net.contains(ip("::1")));
        let all: IpNet = "0.0.0.0/0".parse().unwrap();
        assert!(all.contains(ip("8.8.8.8")));
        let one: IpNet = "2001:db8::1".parse().unwrap();
        assert!(one.contains(ip("2001:db8::1")));
        assert!(!one.contains(ip("2001:db8::2")));
    }
}
