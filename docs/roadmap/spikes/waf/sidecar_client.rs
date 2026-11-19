//! S1 spike: the gateway's side of a Coraza sidecar. Each request goes over one
//! kept-alive Unix socket connection as HTTP/1.1; only the status comes back.
use std::io::{BufRead, BufReader, Read, Write};
use std::os::unix::net::UnixStream;
use std::time::{Duration, Instant};

struct Req { name: String, raw: Vec<u8> }

fn workload(path: &str) -> Vec<Req> {
    std::fs::read_to_string(path).unwrap().lines().map(|line| {
        let f: Vec<&str> = line.splitn(5, '|').collect();
        let mut raw = format!("{} {} HTTP/1.1\r\nHost: shop.example.com\r\nUser-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36\r\nAccept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\nAccept-Language: en-US,en;q=0.9\r\nAccept-Encoding: gzip, deflate, br\r\nCookie: wordpress_logged_in_abc=jane%7C1700000000%7Ctoken; wp-settings-1=libraryContent%3Dbrowse\r\nConnection: keep-alive\r\n", f[1], f[2]);
        if !f[3].is_empty() { raw += &format!("Content-Type: {}\r\n", f[3]); }
        raw += &format!("Content-Length: {}\r\n\r\n{}", f[4].len(), f[4]);
        Req { name: f[0].into(), raw: raw.into_bytes() }
    }).collect()
}

fn send(conn: &mut BufReader<UnixStream>, r: &Req) -> u16 {
    conn.get_mut().write_all(&r.raw).unwrap();
    let mut line = String::new();
    conn.read_line(&mut line).unwrap();
    let status: u16 = line.split(' ').nth(1).unwrap().parse().unwrap();
    let mut length = 0usize;
    loop {
        line.clear();
        conn.read_line(&mut line).unwrap();
        if line == "\r\n" { break; }
        if let Some(v) = line.to_ascii_lowercase().strip_prefix("content-length:") { length = v.trim().parse().unwrap(); }
    }
    let mut body = vec![0; length];
    conn.read_exact(&mut body).unwrap();
    status
}

fn pct(t: &[Duration], p: f64) -> f64 { t[((t.len() - 1) as f64 * p) as usize].as_secs_f64() * 1e6 }

fn main() {
    let a: Vec<String> = std::env::args().collect();
    let (socket, reqs, iters) = (&a[1], workload(&a[2]), a[3].parse::<usize>().unwrap());
    let threads: Vec<usize> = a.get(4).map(|v| v.split(',').map(|n| n.parse().unwrap()).collect()).unwrap_or(vec![1]);
    let mut conn = BufReader::new(UnixStream::connect(socket).unwrap());
    for r in &reqs { for _ in 0..100 { send(&mut conn, r); } }
    for r in &reqs {
        let mut t = Vec::with_capacity(iters);
        let mut s = 0;
        for _ in 0..iters { let i = Instant::now(); s = send(&mut conn, r); t.push(i.elapsed()); }
        t.sort();
        println!("req={} status={s} p50_us={:.0} p95_us={:.0} p99_us={:.0}", r.name, pct(&t, 0.5), pct(&t, 0.95), pct(&t, 0.99));
    }
    for &n in &threads {
        let start = Instant::now();
        std::thread::scope(|sc| for _ in 0..n { sc.spawn(|| {
            let mut c = BufReader::new(UnixStream::connect(socket).unwrap());
            for i in 0..iters { send(&mut c, &reqs[i % reqs.len()]); }
        }); });
        println!("mixed threads={n} req_per_s={:.0}", (n * iters) as f64 / start.elapsed().as_secs_f64());
    }
}
