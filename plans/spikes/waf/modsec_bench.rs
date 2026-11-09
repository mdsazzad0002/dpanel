//! S1 spike: libmodsecurity 3 through its C API, in-process, as the gateway would call it.
use std::ffi::{c_char, c_int, c_void, CStr, CString};
use std::time::{Duration, Instant};

#[repr(C)]
struct Intervention { status: c_int, pause: c_int, url: *mut c_char, log: *mut c_char, disruptive: c_int }

#[link(name = "modsecurity")]
extern "C" {
    fn msc_init() -> *mut c_void;
    fn msc_set_connector_info(ms: *mut c_void, info: *const c_char);
    fn msc_set_log_cb(ms: *mut c_void, cb: extern "C" fn(*mut c_void, *const c_void));
    fn msc_create_rules_set() -> *mut c_void;
    fn msc_rules_add_file(rules: *mut c_void, file: *const c_char, error: *mut *const c_char) -> c_int;
    fn msc_new_transaction(ms: *mut c_void, rules: *mut c_void, logcb: *mut c_void) -> *mut c_void;
    fn msc_process_connection(t: *mut c_void, client: *const c_char, cport: c_int, server: *const c_char, sport: c_int) -> c_int;
    fn msc_process_uri(t: *mut c_void, uri: *const c_char, method: *const c_char, version: *const c_char) -> c_int;
    fn msc_add_n_request_header(t: *mut c_void, key: *const u8, klen: usize, value: *const u8, vlen: usize) -> c_int;
    fn msc_process_request_headers(t: *mut c_void) -> c_int;
    fn msc_append_request_body(t: *mut c_void, body: *const u8, len: usize) -> c_int;
    fn msc_process_request_body(t: *mut c_void) -> c_int;
    fn msc_intervention(t: *mut c_void, it: *mut Intervention) -> c_int;
    fn msc_process_logging(t: *mut c_void) -> c_int;
    fn msc_transaction_cleanup(t: *mut c_void);
}

struct Req { name: String, method: CString, uri: CString, headers: Vec<(String, String)>, body: Vec<u8> }

fn workload(path: &str) -> Vec<Req> {
    std::fs::read_to_string(path).unwrap().lines().map(|line| {
        let f: Vec<&str> = line.splitn(5, '|').collect();
        let mut headers = vec![
            ("Host", "shop.example.com"),
            ("User-Agent", "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36"),
            ("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"),
            ("Accept-Language", "en-US,en;q=0.9"),
            ("Accept-Encoding", "gzip, deflate, br"),
            ("Cookie", "wordpress_logged_in_abc=jane%7C1700000000%7Ctoken; wp-settings-1=libraryContent%3Dbrowse"),
            ("Connection", "keep-alive"),
        ].into_iter().map(|(k, v)| (k.to_string(), v.to_string())).collect::<Vec<_>>();
        if !f[3].is_empty() {
            headers.push(("Content-Type".into(), f[3].into()));
            headers.push(("Content-Length".into(), f[4].len().to_string()));
        }
        Req { name: f[0].into(), method: CString::new(f[1]).unwrap(), uri: CString::new(f[2]).unwrap(), headers, body: f[4].as_bytes().to_vec() }
    }).collect()
}

struct Engine { ms: *mut c_void, rules: *mut c_void }
unsafe impl Send for Engine {}
unsafe impl Sync for Engine {}

/// One request through phases 1 and 2; returns the status ModSecurity asks for (200 = let through).
fn inspect(e: &Engine, r: &Req) -> i32 {
    unsafe {
        let t = msc_new_transaction(e.ms, e.rules, std::ptr::null_mut());
        let ip = c"203.0.113.7"; let sip = c"127.0.0.1"; let ver = c"1.1";
        msc_process_connection(t, ip.as_ptr(), 51234, sip.as_ptr(), 443);
        msc_process_uri(t, r.uri.as_ptr(), r.method.as_ptr(), ver.as_ptr());
        for (k, v) in &r.headers { msc_add_n_request_header(t, k.as_ptr(), k.len(), v.as_ptr(), v.len()); }
        msc_process_request_headers(t);
        let mut it = Intervention { status: 200, pause: 0, url: std::ptr::null_mut(), log: std::ptr::null_mut(), disruptive: 0 };
        let mut status = 200;
        if msc_intervention(t, &mut it) != 0 { status = it.status; }
        if status == 200 {
            if !r.body.is_empty() { msc_append_request_body(t, r.body.as_ptr(), r.body.len()); }
            msc_process_request_body(t);
            if msc_intervention(t, &mut it) != 0 { status = it.status; }
        }
        if !it.log.is_null() { libc_free(it.log as *mut c_void); }
        if !it.url.is_null() { libc_free(it.url as *mut c_void); }
        msc_process_logging(t);
        msc_transaction_cleanup(t);
        status
    }
}

/// A gateway would send this to its own log; the benchmark drops it.
extern "C" fn quiet(_data: *mut c_void, _msg: *const c_void) {}

extern "C" { #[link_name = "free"] fn libc_free(p: *mut c_void); }

fn rss_mb() -> f64 {
    std::fs::read_to_string("/proc/self/status").unwrap().lines()
        .find_map(|l| l.strip_prefix("VmRSS:").map(|v| v.trim().trim_end_matches(" kB").parse::<f64>().unwrap() / 1024.0)).unwrap()
}

fn pct(sorted: &[Duration], p: f64) -> f64 { sorted[((sorted.len() as f64 - 1.0) * p) as usize].as_secs_f64() * 1e6 }

fn main() {
    let args: Vec<String> = std::env::args().collect();
    let (rules_file, workload_file) = (&args[1], &args[2]);
    let iterations: usize = args.get(3).map(|v| v.parse().unwrap()).unwrap_or(5000);
    let threads: Vec<usize> = args.get(4).map(|v| v.split(',').map(|n| n.parse().unwrap()).collect()).unwrap_or(vec![1, 4, 8]);
    let reqs = workload(workload_file);

    let rss0 = rss_mb();
    let started = Instant::now();
    let engine = unsafe {
        let ms = msc_init();
        msc_set_connector_info(ms, c"dpanel-spike".as_ptr());
        msc_set_log_cb(ms, quiet);
        let rules = msc_create_rules_set();
        let mut err: *const c_char = std::ptr::null();
        let file = CString::new(rules_file.as_str()).unwrap();
        let n = msc_rules_add_file(rules, file.as_ptr(), &mut err);
        if n < 0 { panic!("rules: {}", CStr::from_ptr(err).to_string_lossy()); }
        println!("engine=libmodsecurity rules_loaded={n} load_ms={:.0} rss_rules_mb={:.1}", started.elapsed().as_secs_f64() * 1e3, rss_mb() - rss0);
        Engine { ms, rules }
    };

    for r in &reqs { for _ in 0..200 { inspect(&engine, r); } }
    for r in &reqs {
        let mut times = Vec::with_capacity(iterations);
        let mut status = 0;
        for _ in 0..iterations { let t = Instant::now(); status = inspect(&engine, r); times.push(t.elapsed()); }
        times.sort();
        println!("req={} status={status} p50_us={:.0} p95_us={:.0} p99_us={:.0}", r.name, pct(&times, 0.5), pct(&times, 0.95), pct(&times, 0.99));
    }
    for &n in &threads {
        let per = iterations;
        let t = Instant::now();
        std::thread::scope(|s| { for _ in 0..n { s.spawn(|| { for i in 0..per { inspect(&engine, &reqs[i % reqs.len()]); } }); } });
        let secs = t.elapsed().as_secs_f64();
        println!("mixed threads={n} req_per_s={:.0}", (n * per) as f64 / secs);
    }
    println!("rss_end_mb={:.1}", rss_mb());
}
