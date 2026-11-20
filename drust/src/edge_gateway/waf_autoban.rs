//! Bans an IP at the firewall after the WAF blocked it many times.
//!
//! A site in `block` mode answers each attack with a 403, but the attacker
//! keeps sending, and every request still costs a WAF inspection. Once one
//! address is blocked [`hits`] times within [`WINDOW`], it is denied at the
//! server's firewall (UFW or firewalld), so its traffic never reaches the
//! gateway. The first ban lasts [`ban_minutes`]; an address banned again
//! within [`STRIKE_MEMORY`] gets a day, and from its third ban on a week.
//! Bans are kept in `<state_dir>/waf-bans.json` with their expiry and lifted
//! by a sweep, also after a restart. The panel's firewall page lists them
//! ([`list`]) and an admin can lift one early ([`lift`]), which also forgets
//! the address's earlier bans.
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
use serde::{Deserialize, Serialize};
use tracing::{info, warn};

use crate::platform::{self, firewall::IpNet};

/// Blocks within this long count towards a ban.
const WINDOW: Duration = Duration::from_secs(600);
/// How often expired bans are lifted.
const SWEEP_EVERY: Duration = Duration::from_secs(60);
/// Addresses tracked at once; the oldest are dropped past this.
const MAX_TRACKED: usize = 50_000;
/// How long a lifted ban still counts when the address is banned again.
const STRIKE_MEMORY: Duration = Duration::from_secs(30 * 24 * 3600);
/// Second ban: a day. Third and later: a week.
const SECOND_BAN_MINUTES: u64 = 24 * 60;
const REPEAT_BAN_MINUTES: u64 = 7 * 24 * 60;

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
    platform::current().os() == platform::Os::Linux
        && !matches!(
            std::env::var("DRUST_WAF_AUTOBAN").ok().as_deref().map(str::trim),
            Some("0" | "false" | "off" | "no")
        )
}

fn now_secs() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |since| since.as_secs())
}

/// One address's ban record. Kept after the ban is lifted, for
/// [`STRIKE_MEMORY`], so a returning attacker is banned for longer.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
struct Ban {
    /// When the ban ends (or ended), in seconds since 1970.
    until: u64,
    /// Bans so far, this one included.
    strikes: u32,
    /// Still denied at the firewall.
    active: bool,
}

/// The store as written before strikes existed: address → ban end.
#[derive(Deserialize)]
#[serde(untagged)]
enum Stored {
    Ban(Ban),
    Until(u64),
}

#[derive(Default)]
struct State {
    blocks: HashMap<IpAddr, VecDeque<Instant>>,
    bans: BTreeMap<IpAddr, Ban>,
}

impl State {
    fn is_banned(&self, ip: &IpAddr) -> bool {
        self.bans.get(ip).is_some_and(|ban| ban.active)
    }
}

/// A current ban, as the panel shows it.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct BanView {
    pub ip: IpAddr,
    /// Seconds since 1970.
    pub until: u64,
    pub strikes: u32,
}

/// How long the `strikes`-th ban lasts, never shorter than the configured first ban.
fn ban_length_minutes(strikes: u32, first: u64) -> u64 {
    match strikes {
        0 | 1 => first,
        2 => SECOND_BAN_MINUTES.max(first),
        _ => REPEAT_BAN_MINUTES.max(first),
    }
}

/// The strikes for a new ban at `now`, given the address's earlier record.
fn next_strikes(previous: Option<&Ban>, now: u64) -> u32 {
    previous
        .filter(|ban| ban.until + STRIKE_MEMORY.as_secs() > now)
        .map_or(1, |ban| ban.strikes.saturating_add(1))
}

fn state() -> &'static Mutex<State> {
    static STATE: OnceLock<Mutex<State>> = OnceLock::new();
    STATE.get_or_init(|| Mutex::new(State { bans: read_store(), ..State::default() }))
}

fn store_path() -> PathBuf {
    platform::paths().state_dir().join("waf-bans.json")
}

fn read_store() -> BTreeMap<IpAddr, Ban> {
    fs::read_to_string(store_path()).ok().map(|content| parse_store(&content)).unwrap_or_default()
}

fn parse_store(content: &str) -> BTreeMap<IpAddr, Ban> {
    serde_json::from_str::<BTreeMap<IpAddr, Stored>>(content)
        .unwrap_or_default()
        .into_iter()
        .map(|(ip, stored)| {
            let ban = match stored {
                Stored::Ban(ban) => ban,
                Stored::Until(until) => Ban { until, strikes: 1, active: true },
            };
            (ip, ban)
        })
        .collect()
}

fn write_store(banned: &BTreeMap<IpAddr, Ban>) {
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
    if state.is_banned(&ip) {
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
    let now = now_secs();
    let strikes = {
        let state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        next_strikes(state.bans.get(&ip), now)
    };
    let minutes = ban_length_minutes(strikes, ban_minutes());
    if let Err(error) = platform::firewall::backend().ban(ip, Some(Duration::from_secs(minutes * 60))) {
        warn!(%ip, %error, "WAF auto-ban: the firewall refused the ban");
        return;
    }
    info!(%ip, minutes, strikes, "WAF auto-ban: address banned at the firewall after repeated blocks");
    let mut state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    state.bans.insert(ip, Ban { until: now + minutes * 60, strikes, active: true });
    write_store(&state.bans);
}

/// The bans in force, soonest to end first.
pub fn list() -> Vec<BanView> {
    let state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    let mut bans: Vec<BanView> = state
        .bans
        .iter()
        .filter(|(_, ban)| ban.active)
        .map(|(ip, ban)| BanView { ip: *ip, until: ban.until, strikes: ban.strikes })
        .collect();
    bans.sort_by_key(|ban| ban.until);
    bans
}

/// Lifts `ip`'s ban now and forgets its earlier ones. `Ok(false)` when it
/// was not banned.
pub fn lift(ip: IpAddr) -> Result<bool, String> {
    let known = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner()).is_banned(&ip);
    if !known {
        return Ok(false);
    }
    platform::firewall::backend().unban(ip).map_err(|error| error.to_string())?;
    info!(%ip, "WAF auto-ban: lifted by an admin");
    let mut state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    state.bans.remove(&ip);
    state.blocks.remove(&ip);
    write_store(&state.bans);
    Ok(true)
}

/// Lifts the bans whose time is up.
fn sweep() {
    let now = now_secs();
    let (expired, forgotten) = {
        let state = state().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        let expired: Vec<IpAddr> =
            state.bans.iter().filter(|(_, ban)| ban.active && ban.until <= now).map(|(ip, _)| *ip).collect();
        let forgotten = state.bans.values().any(|ban| !ban.active && ban.until + STRIKE_MEMORY.as_secs() <= now);
        (expired, forgotten)
    };
    if expired.is_empty() && !forgotten {
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
        if let Some(ban) = state.bans.get_mut(ip) {
            ban.active = false;
        }
    }
    state.bans.retain(|_, ban| ban.active || ban.until + STRIKE_MEMORY.as_secs() > now);
    write_store(&state.bans);
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
        state.bans.insert(ip("203.0.113.7"), Ban { until: u64::MAX, strikes: 1, active: true });
        assert!(!count_block(&mut state, ip("203.0.113.7"), Instant::now(), 1));
        // A lifted ban no longer shields the address from counting.
        state.bans.insert(ip("203.0.113.7"), Ban { until: 0, strikes: 1, active: false });
        assert!(count_block(&mut state, ip("203.0.113.7"), Instant::now(), 1));
    }

    #[test]
    fn a_returning_attacker_is_banned_longer() {
        assert_eq!(ban_length_minutes(1, 60), 60);
        assert_eq!(ban_length_minutes(2, 60), 24 * 60);
        assert_eq!(ban_length_minutes(3, 60), 7 * 24 * 60);
        assert_eq!(ban_length_minutes(9, 60), 7 * 24 * 60);
        // A longer configured first ban is never shortened.
        assert_eq!(ban_length_minutes(2, 3 * 24 * 60), 3 * 24 * 60);
    }

    #[test]
    fn strikes_are_forgotten_after_a_month() {
        let day = 24 * 3600;
        let earlier = Ban { until: 100 * day, strikes: 2, active: false };
        assert_eq!(next_strikes(None, 100 * day), 1);
        assert_eq!(next_strikes(Some(&earlier), 110 * day), 3);
        assert_eq!(next_strikes(Some(&earlier), 131 * day), 1);
    }

    #[test]
    fn reads_the_store_written_before_strikes() {
        let bans = parse_store(r#"{"203.0.113.7": 1700000000, "198.51.100.4": {"until": 5, "strikes": 3, "active": false}}"#);
        assert_eq!(bans[&ip("203.0.113.7")], Ban { until: 1_700_000_000, strikes: 1, active: true });
        assert_eq!(bans[&ip("198.51.100.4")], Ban { until: 5, strikes: 3, active: false });
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
