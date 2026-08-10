use std::{
    fs,
    io::{Read, Write},
    os::unix::fs::PermissionsExt,
    path::{Path, PathBuf},
    process::Command,
    sync::Arc,
    time::{Duration, SystemTime, UNIX_EPOCH},
};

use axum::{
    Json,
    extract::{
        Query, State, WebSocketUpgrade,
        ws::{Message, WebSocket},
    },
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
};
use futures_util::{SinkExt, StreamExt};
use portable_pty::{CommandBuilder, PtySize, native_pty_system};
use serde::{Deserialize, Serialize};
use tokio::sync::mpsc;

use super::server::DemoServerState;

#[derive(Clone, Deserialize)]
pub struct TerminalTicket {
    pub ticket: String,
    pub site_owner: String,
    pub project_root: String,
    pub expires_at: u64,
    pub host: String,
}

#[derive(Serialize)]
struct TicketResponse {
    success: bool,
}

pub async fn register_ticket(
    State(state): State<Arc<DemoServerState>>,
    headers: HeaderMap,
    Json(ticket): Json<TerminalTicket>,
) -> Response {
    let configured = std::env::var("DRUST_API_TOKEN").unwrap_or_default();
    let supplied = headers
        .get("authorization")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
        .unwrap_or("");
    if configured.is_empty() || supplied != configured {
        return StatusCode::UNAUTHORIZED.into_response();
    }
    if validate_ticket(&ticket).is_err() {
        return StatusCode::UNPROCESSABLE_ENTITY.into_response();
    }
    state
        .terminal_tickets
        .lock()
        .await
        .insert(ticket.ticket.clone(), ticket);
    Json(TicketResponse { success: true }).into_response()
}

#[derive(Deserialize)]
pub struct TicketQuery {
    ticket: String,
}

pub async fn terminal_socket(
    State(state): State<Arc<DemoServerState>>,
    Query(query): Query<TicketQuery>,
    headers: HeaderMap,
    ws: WebSocketUpgrade,
) -> Response {
    let mut tickets = state.terminal_tickets.lock().await;
    tickets.retain(|_, value| value.expires_at >= now());
    let Some(ticket) = tickets.remove(&query.ticket) else {
        return StatusCode::UNAUTHORIZED.into_response();
    };
    drop(tickets);
    let host = headers
        .get("host")
        .and_then(|v| v.to_str().ok())
        .unwrap_or("")
        .split(':')
        .next()
        .unwrap_or("");
    let origin_host = headers
        .get("origin")
        .and_then(|v| v.to_str().ok())
        .and_then(|value| value.split_once("://").map(|(_, rest)| rest))
        .and_then(|rest| rest.split('/').next())
        .unwrap_or("")
        .split(':')
        .next()
        .unwrap_or("");
    if !host.eq_ignore_ascii_case(&ticket.host)
        || !origin_host.eq_ignore_ascii_case(&ticket.host)
        || validate_ticket(&ticket).is_err()
    {
        return StatusCode::FORBIDDEN.into_response();
    }
    ws.on_upgrade(move |socket| run_terminal(socket, ticket))
}

fn validate_ticket(ticket: &TerminalTicket) -> Result<(), ()> {
    if ticket.ticket.len() < 48
        || !ticket.ticket.chars().all(|c| c.is_ascii_alphanumeric())
        || ticket.expires_at < now()
        || ticket.expires_at > now() + 90
    {
        return Err(());
    }
    if !ticket
        .site_owner
        .chars()
        .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-')
    {
        return Err(());
    }
    let root = Path::new(&ticket.project_root)
        .canonicalize()
        .map_err(|_| ())?;
    let home = Path::new("/home")
        .join(&ticket.site_owner)
        .canonicalize()
        .map_err(|_| ())?;
    if root != home || !root.is_dir() {
        return Err(());
    }
    Ok(())
}

fn now() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

async fn run_terminal(socket: WebSocket, ticket: TerminalTicket) {
    let Ok((mut child, mut reader, writer, master, identity_dir)) = spawn_pty(&ticket) else {
        return;
    };
    let writer = Arc::new(std::sync::Mutex::new(writer));
    let (output_tx, mut output_rx) = mpsc::channel::<Vec<u8>>(64);
    std::thread::spawn(move || {
        let mut buffer = [0u8; 8192];
        loop {
            match reader.read(&mut buffer) {
                Ok(0) | Err(_) => break,
                Ok(n) => {
                    if output_tx.blocking_send(buffer[..n].to_vec()).is_err() {
                        break;
                    }
                }
            }
        }
    });
    let (mut sender, mut receiver) = socket.split();
    let idle = tokio::time::sleep(Duration::from_secs(180));
    tokio::pin!(idle);
    loop {
        tokio::select! {
            value = output_rx.recv() => match value { Some(bytes) => { if sender.send(Message::Binary(bytes.into())).await.is_err() { break; } }, None => break },
            value = receiver.next() => match value {
                Some(Ok(Message::Binary(bytes))) => { if bytes.len() > 8192 { break; } if writer.lock().ok().and_then(|mut w| w.write_all(&bytes).ok()).is_none() { break; } idle.as_mut().reset(tokio::time::Instant::now() + Duration::from_secs(180)); },
                Some(Ok(Message::Text(text))) => { if let Ok(size) = serde_json::from_str::<Resize>(&text) { let _ = master.resize(PtySize { rows: size.rows.clamp(10, 200), cols: size.cols.clamp(20, 400), pixel_width: 0, pixel_height: 0 }); } },
                Some(Ok(Message::Close(_))) | None | Some(Err(_)) => break,
                _ => {}
            },
            _ = &mut idle => { let _ = sender.send(Message::Text("\r\n[session closed after 3 minutes of inactivity]\r\n".into())).await; break; }
        }
    }
    let _ = child.kill();
    let _ = child.wait();
    let _ = fs::remove_dir_all(identity_dir);
}

#[derive(Deserialize)]
struct Resize {
    rows: u16,
    cols: u16,
}

fn account_id(flag: &str, owner: &str) -> Result<String, String> {
    let output = Command::new("id")
        .args([flag, owner])
        .output()
        .map_err(|e| e.to_string())?;
    let value = String::from_utf8_lossy(&output.stdout).trim().to_string();
    if !output.status.success() || !value.chars().all(|c| c.is_ascii_digit()) {
        return Err("invalid owner".into());
    }
    Ok(value)
}

fn create_identity_files(ticket: &TerminalTicket, uid: &str, gid: &str) -> Result<PathBuf, String> {
    let dir = std::env::temp_dir().join(format!("dpanel-terminal-{}", ticket.ticket));
    fs::create_dir(&dir).map_err(|e| e.to_string())?;
    fs::set_permissions(&dir, fs::Permissions::from_mode(0o700)).map_err(|e| e.to_string())?;
    let passwd = format!(
        "{}:x:{}:{}::{}:/bin/bash\n",
        ticket.site_owner, uid, gid, ticket.project_root
    );
    let group = format!("{}:x:{}:{}\n", ticket.site_owner, gid, ticket.site_owner);
    if let Err(error) =
        fs::write(dir.join("passwd"), passwd).and_then(|_| fs::write(dir.join("group"), group))
    {
        let _ = fs::remove_dir_all(&dir);
        return Err(error.to_string());
    }
    for protected in ["empty-ssh", "empty-dpanel"] {
        let path = dir.join(protected);
        fs::create_dir(&path).map_err(|e| e.to_string())?;
        fs::set_permissions(path, fs::Permissions::from_mode(0o500)).map_err(|e| e.to_string())?;
    }
    Ok(dir)
}

fn ensure_protected_mountpoint(root: &Path, name: &str) -> Result<(), String> {
    let path = root.join(name);
    match fs::symlink_metadata(&path) {
        Ok(metadata) if metadata.file_type().is_symlink() || !metadata.is_dir() => {
            return Err(format!(
                "unsafe protected terminal path: {}",
                path.display()
            ));
        }
        Ok(_) => {}
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
            fs::create_dir(&path).map_err(|e| e.to_string())?;
        }
        Err(error) => return Err(error.to_string()),
    }
    fs::set_permissions(path, fs::Permissions::from_mode(0o700)).map_err(|e| e.to_string())
}

fn spawn_pty(
    ticket: &TerminalTicket,
) -> Result<
    (
        Box<dyn portable_pty::Child + Send + Sync>,
        Box<dyn Read + Send>,
        Box<dyn Write + Send>,
        Box<dyn portable_pty::MasterPty + Send>,
        PathBuf,
    ),
    String,
> {
    let uid = account_id("-u", &ticket.site_owner)?;
    let gid = account_id("-g", &ticket.site_owner)?;
    let root = Path::new(&ticket.project_root);
    ensure_protected_mountpoint(root, ".ssh")?;
    ensure_protected_mountpoint(root, ".dpanel")?;
    let identity_dir = create_identity_files(ticket, &uid, &gid)?;
    let passwd_path = identity_dir.join("passwd").to_string_lossy().into_owned();
    let group_path = identity_dir.join("group").to_string_lossy().into_owned();
    let protected_ssh = identity_dir
        .join("empty-ssh")
        .to_string_lossy()
        .into_owned();
    let protected_dpanel = identity_dir
        .join("empty-dpanel")
        .to_string_lossy()
        .into_owned();
    let protected_ssh_target = format!("{}/.ssh", ticket.project_root);
    let protected_dpanel_target = format!("{}/.dpanel", ticket.project_root);
    let prompt = format!("[{}@dpanel \\W]\\$ ", ticket.site_owner);
    let pty = native_pty_system()
        .openpty(PtySize {
            rows: 30,
            cols: 120,
            pixel_width: 0,
            pixel_height: 0,
        })
        .map_err(|e| e.to_string())?;
    let mut cmd = CommandBuilder::new("prlimit");
    // Let bubblewrap switch the final process identity while it still has the
    // privileges needed to build the sandbox. A setpriv process *inside* the
    // sandbox cannot call setresuid after bubblewrap has dropped capabilities.
    for arg in [
        "--as=1073741824",
        "--nproc=128",
        "--nofile=256",
        "--",
        "bwrap",
        "--unshare-user",
        "--unshare-pid",
        "--unshare-net",
        "--unshare-ipc",
        "--unshare-uts",
        "--unshare-cgroup-try",
        "--die-with-parent",
        "--uid",
        &uid,
        "--gid",
        &gid,
        "--ro-bind",
        "/usr",
        "/usr",
        "--symlink",
        "usr/bin",
        "/bin",
        "--symlink",
        "usr/lib",
        "/lib",
        "--symlink",
        "usr/lib64",
        "/lib64",
        "--proc",
        "/proc",
        "--dev",
        "/dev",
        "--tmpfs",
        "/tmp",
        "--dir",
        "/etc",
        "--ro-bind",
        &passwd_path,
        "/etc/passwd",
        "--ro-bind",
        &group_path,
        "/etc/group",
        "--dir",
        "/home",
        "--bind",
        &ticket.project_root,
        &ticket.project_root,
        "--ro-bind",
        &protected_ssh,
        &protected_ssh_target,
        "--ro-bind",
        &protected_dpanel,
        &protected_dpanel_target,
        "--chdir",
        &ticket.project_root,
        "--clearenv",
        "--setenv",
        "HOME",
        &ticket.project_root,
        "--setenv",
        "PATH",
        "/usr/local/bin:/usr/bin:/bin",
        "--setenv",
        "TERM",
        "xterm-256color",
        "--setenv",
        "PS1",
        &prompt,
        "script",
        "-qfec",
        "bash --noprofile --norc -i",
        "/dev/null",
    ] {
        cmd.arg(arg);
    }
    let child = match pty.slave.spawn_command(cmd) {
        Ok(child) => child,
        Err(error) => {
            let _ = fs::remove_dir_all(&identity_dir);
            return Err(error.to_string());
        }
    };
    let reader = pty.master.try_clone_reader().map_err(|e| e.to_string())?;
    let writer = pty.master.take_writer().map_err(|e| e.to_string())?;
    Ok((child, reader, writer, pty.master, identity_dir))
}
