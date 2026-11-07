# Platform Technical Spec

Linux, Windows ও macOS-এ dPanel চালানোর technical contract। Backlog-এর প্রতিটা task এই spec মেনে চলবে। [backlog.md](backlog.md)-এর task ID গুলো এখানকার section-এর দিকে নির্দেশ করে।

এই document-এর signature গুলো contract, চূড়ান্ত code নয়। Implementation-এর সময় নাম বদলালে এই file-ও একই commit-এ বদলাতে হবে।

## 1. নিয়ম

1. Platform layer-এর ওপরের কোনো code OS-এর নাম জানবে না।
2. `#[cfg(windows)]`, `#[cfg(target_os = "macos")]` ও `#[cfg(unix)]` শুধু `drust/src/platform/`-এর ভেতরে থাকবে।
3. কোনো OS-এ কিছু না থাকলে `PlatformError::Unsupported` ফেরত দেবে, আর capability flag সেটা জানাবে। Silent no-op চলবে না, শুধু যেখানে এই spec বলেছে সেখানে ছাড়া।
4. Linux-এর বর্তমান আচরণ বদলাবে না। Linux implementation-এ কোনো command বা path বদলালে সেটা আলাদা task।
5. Shell string বানানো চলবে না। User input সবসময় আলাদা argument হিসেবে যাবে।

## 2. Profile ও build

| জিনিস | মান |
| --- | --- |
| Profile env | `DPANEL_PROFILE=server` (default) বা `local`; `local` ছাড়া বাকি সব মান `server` |
| Cargo features | `speech` (`whisper-rs`), `ocr` (বিদ্যমান) |
| Linux default | `speech`, `ocr` চালু; বর্তমান আচরণ অপরিবর্তিত |
| Windows, macOS default | দুটোই বন্ধ; Cargo-র default target-ভেদে আলাদা হয় না, তাই build-এ `--no-default-features` |
| Release target | `x86_64-unknown-linux-gnu`, `x86_64-pc-windows-msvc`, `aarch64-apple-darwin`, `x86_64-apple-darwin` |

`speech` বন্ধ থাকলে `drust/src/api/media.rs`-এর transcribe `ocr` feature-এর মতোই পরিষ্কার error দেবে।

## 3. Module layout

```text
drust/src/platform/
  mod.rs          Platform struct, current(), PlatformError
  capabilities.rs Capability enum ও report
  paths.rs        Paths trait ও OS map
  fs.rs           FileAccess
  fastcgi.rs      FastCgiEndpoint
  service.rs      ServiceManager
  account.rs      AccountManager
  runner.rs       IsolatedRunner
  limits.rs       ResourceLimiter
  firewall.rs     FirewallBackend
  dns.rs          DnsBackend
  acme.rs         AcmeClient
  scheduler.rs    Scheduler
  supervisor.rs   Local ও macOS/Windows-এর child process supervisor
  unix/           Linux ও macOS-এ একই code, যেমন FileAccess
  linux/  macos/  windows/
```

Laravel-এর দিকে:

```text
dpanel/app/Support/Platform/
  PlatformCapabilities.php   drust থেকে capability পড়ে, cache করে
  ProcessIdentity.php        posix_* এর বদলে
  LocalCommand.php           OS-নিরপেক্ষ command চালানো
dpanel/app/Http/Middleware/RequireCapability.php
dpanel/app/Services/Mail/Backends/{MailBackend,PostfixDovecotBackend,StalwartBackend}.php
```

## 4. Error contract

```rust
pub enum PlatformError {
    Unsupported { capability: Capability },
    NotFound { what: String },
    PermissionDenied { what: String },
    Conflict { what: String },
    Failed { action: String, detail: String },
}
```

drust API-তে `Unsupported` মানে HTTP `501` ও `{"error":"unsupported","capability":"<name>"}`।

`Display` OS-এর দেওয়া লেখাই দেখায়, কারণ caller নিজেই বলে সে কী করছিল (`cannot write ticket: {e}`)। তাই Linux-এর error message বদলায় না।

## 5. Capability

`GET /capabilities` (token দরকার):

```json
{
  "os": "windows",
  "profile": "server",
  "version": "1.0.0",
  "capabilities": {
    "websites": true, "php_multi": true, "isolation": "hard",
    "resource_limits": "hard", "disk_quota": "hard",
    "mail": "stalwart", "dns": "windows_dns", "ssl": "acme",
    "firewall": true, "ftp": true, "terminal": false, "docker": false,
    "redis": false, "reseller": true, "file_permissions": false
  }
}
```

| Capability | Linux server | Windows server | macOS server | Local (দুই OS) |
| --- | --- | --- | --- | --- |
| `isolation` | `hard` | `hard` | `hard` | `none` |
| `resource_limits` | `hard` | `hard` | `soft` | `none` |
| `disk_quota` | `hard` | `hard` | `hard` | `none` |
| `mail` | `postfix` | `stalwart` | `stalwart` | `catcher` |
| `dns` | `powerdns` | `windows_dns` | `external` | `hosts` |
| `ssl` | `certbot`, পরে `acme` | `acme` | `acme` | `local_ca` |
| `firewall` | হ্যাঁ | হ্যাঁ | হ্যাঁ | না |
| `ftp` | হ্যাঁ | হ্যাঁ | হ্যাঁ | না |
| `terminal` | হ্যাঁ | না | না | হ্যাঁ |
| `docker` | হ্যাঁ | না | না | Docker Desktop থাকলে |
| `redis` | হ্যাঁ | না | হ্যাঁ | Windows-এ না, বাকিতে হ্যাঁ |
| `reseller` | হ্যাঁ | হ্যাঁ | হ্যাঁ | না |
| `file_permissions` | হ্যাঁ | না (F1 পর্যন্ত) | হ্যাঁ | Windows-এ না, বাকিতে হ্যাঁ |

Laravel-এ route গুলো `capability:<name>` middleware দিয়ে আটকাবে। Sidebar ও search একই তথ্য Inertia shared prop থেকে নেবে। Capability বন্ধ থাকলে route `404` দেবে।

কোন route কোন capability চায়, সেটা `PlatformCapabilities::ROUTE_CAPABILITIES`-এর route-name prefix table-এ থাকে; `routes/web.php`-এর শেষে সেখান থেকে middleware বসে। `false` ও `"none"` মানে বন্ধ, বাকি সব মান খোলা।

drust উত্তর না দিলে (বন্ধ, পুরোনো version, ভুল token) সব capability খোলা ধরা হয়, নইলে drust-এর সঙ্গে পুরো panel পড়ে যেত। এটা security boundary নয়: যে feature নেই, drust নিজেই `501` দেয়। ফল 5 মিনিট cache হয়, ব্যর্থ হলে 1 মিনিট।

## 6. Interface

### 6.1 Paths

```rust
pub struct Paths { /* OS ও profile থেকে হিসাব করা, cfg ছাড়া */ }

impl Paths {
    fn panel_root(&self) -> &Path;
    fn panel_root_candidates(&self) -> Vec<PathBuf>; // Linux-এ পুরোনো install-এর জায়গাও
    fn data_dir(&self) -> &Path;
    fn storage_dir(&self) -> PathBuf; // panel_root/storage/app
    fn tls_dir(&self) -> PathBuf;     // config_dir/tls
    fn sites_root(&self) -> &Path;
    fn site_home(&self, owner: &str) -> PathBuf;
    fn binary(&self, tool: Tool) -> Result<PathBuf, PlatformError>;
    fn php_candidates(&self, version: Option<&str>) -> Vec<PathBuf>; // সেরাটা আগে
    fn php_cli(&self, version: Option<&str>) -> PathBuf;             // যেটা চালানো হবে
    fn php_cgi(&self, version: &str) -> Result<PathBuf, PlatformError>; // শুধু Windows
    fn python_candidates(&self, version: &str) -> Vec<PathBuf>;
    fn managed_python_dir(&self) -> PathBuf;  // drust-এর বসানো Python ও uv
    fn default_document_root(&self, domain: &str) -> PathBuf;
    fn run_dir(&self) -> &Path;
    fn temp_dir(&self) -> &Path;
    fn runtime_dir(&self) -> &Path;   // dPanel-এর নিজের software, Linux-এ /opt/dpanel
    fn state_dir(&self) -> &Path;     // Linux-এ /var/lib/drust, data_dir থেকে আলাদা
    fn install_root(&self) -> &Path;  // panel_root-এর parent
    // log_dir: কোনো code ব্যবহার করলে তখন
}
```

`binary` Linux-এ শুধু নির্দিষ্ট candidate path দেখে, `PATH` নয়, কারণ drust root হিসেবে চলে। macOS-এ সঙ্গে `/opt/homebrew/bin`, Windows-এ `PATH`।

`binary(Tool::Timeout)` Windows-এ সবসময় `NotFound`, কারণ সেখানকার `timeout.exe` শুধু console থামায়। Caller তখন time limit ছাড়া চালায় না, কাজটা বন্ধ রাখে।

`site_home` owner-কে `join` করে না, জুড়ে দেয়: `join` absolute নাম পেলে base ফেলে দেয়, তাই `/etc` নামের owner ownership check ভেঙে দিত। `default_document_root`-ও একই কারণে domain জুড়ে দেয়।

`php_cli` Linux-এ সবসময় প্রথম জায়গাটাই দেয় (`/usr/bin/php`, `/usr/bin/php8.3`), না থাকলেও; অন্য জায়গার copy ভুলে চলবে না, আর না থাকলে caller জানায়। macOS ও Windows-এ প্রথম যেটা আছে সেটা। `version` caller আগেই যাচাই করে।

| জিনিস | Linux | macOS | Windows |
| --- | --- | --- | --- |
| PHP, version সহ | `/usr/bin/php{v}`, `/usr/local/bin/php{v}` | `/opt/homebrew/opt/php@{v}/bin/php`, `/usr/local/opt/php@{v}/bin/php` | `runtime_dir\php\{v}\php.exe` |
| PHP, version ছাড়া | `/usr/bin/php`, `/usr/local/bin/php`, `/usr/bin/php8.3`, `/usr/bin/php8.2` | `/opt/homebrew/bin/php`, `/usr/local/bin/php` | `runtime_dir\php\php.exe`, তারপর `PATH` |
| Python | `/usr/local/pyenv/versions/{v}/bin/python3`, `/usr/bin/python{v}`, `/usr/local/bin/python{v}` | `/opt/homebrew/bin/python{v}`, `/usr/local/bin/python{v}` | `runtime_dir\python\{v}\python.exe` |
| `managed_python_dir` | `/usr/local/dpanel-python` | `runtime_dir/python` | `runtime_dir\python` |
| `default_document_root` | `/var/www/{domain}/public` | `site_home(domain)/public` | `site_home(domain)\public` |

`php_cgi` প্রথমে `runtime_dir\php\{v}\php-cgi.exe` দেখে। তারপর `PATH`-এর `php.exe`-এর parent folder-এ `php{v}.{patch}` folder খোঁজে, সবচেয়ে নতুন patch আগে (যেমন WampServer)। অন্য version কখনও নেয় না।

Local profile-এ (Windows) `DPANEL_PANEL_ROOT` `panel_root` বদলায়, কারণ developer-এর panel তার checkout-এ থাকে। Linux এটা দেখে না।

`uv` অন্য tool-এর মতো `binary(Tool::Uv)` দিয়ে পাওয়া যায়। Windows-এর `runtime_dir\php` ও `runtime_dir\python` এখন শুধু নাম; F3 সেখানে install করলে এই table মিলিয়ে নিতে হবে।

`platform::paths()` দিয়ে পাওয়া যায়। Trait নয়, কারণ OS-ভেদে শুধু মান আলাদা, আচরণ নয়; তাই পুরো table সব OS-এ test করা যায়। Linux profile দেখে না।

| Path | Linux | Windows server | Windows local | macOS server | macOS local |
| --- | --- | --- | --- | --- | --- |
| `panel_root` | `/var/www/dpanel` | `%ProgramFiles%\dPanel\panel` | `%LOCALAPPDATA%\dPanel\panel` | `/Library/dPanel/panel` | `~/Library/Application Support/dPanel/panel` |
| `config_dir` | `/etc/drust` | `%ProgramData%\dPanel\config` | `%LOCALAPPDATA%\dPanel\config` | `/Library/dPanel/config` | `.../dPanel/config` |
| `data_dir` | `/var/lib/dpanel` | `%ProgramData%\dPanel\data` | `%LOCALAPPDATA%\dPanel\data` | `/Library/dPanel/data` | `.../dPanel/data` |
| `log_dir` | `/var/log/dpanel` | `%ProgramData%\dPanel\logs` | `%LOCALAPPDATA%\dPanel\logs` | `/Library/Logs/dPanel` | `~/Library/Logs/dPanel` |
| `sites_root` | `/home` | `%ProgramData%\dPanel\sites` | `%USERPROFILE%\dPanel\sites` | `/Users/Shared/dPanelSites` | `~/dPanel/sites` |

Linux column-এর `data_dir` ও `log_dir` বর্তমান code-এর সঙ্গে মিলিয়ে নিতে হবে। মিল না থাকলে বর্তমান path-ই থাকবে।

### 6.2 FileAccess

এই interface Windows-এর 60টা compile error-এর বেশিরভাগ দূর করে।

```rust
pub enum FileMode { Private, PrivateDir, SiteDir, SiteFile, Shared, Executable, Raw(u32) }
impl FileMode { fn bits(self) -> u32; }
pub enum LinkPolicy { Follow, Refuse }

pub trait FileAccess {
    fn set_mode(&self, path: &Path, mode: FileMode) -> Result<(), PlatformError>;
    fn mode_bits(&self, metadata: &Metadata) -> Option<u32>;
    fn owner(&self, metadata: &Metadata) -> Result<OwnerId, PlatformError>;
    fn set_owner(&self, path: &Path, owner: &OwnerId) -> Result<(), PlatformError>;
    fn set_link_owner(&self, path: &Path, owner: &OwnerId) -> Result<(), PlatformError>;
    fn create_private(&self, path: &Path) -> Result<File, PlatformError>;
    fn replace_private(&self, path: &Path, links: LinkPolicy) -> Result<File, PlatformError>;
    fn create_dir_private(&self, path: &Path) -> Result<(), PlatformError>;
    fn symlink(&self, target: &Path, link: &Path) -> Result<(), PlatformError>;
    fn remove_file(&self, path: &Path) -> Result<(), PlatformError>;
}
```

- `mode_bits` ও `owner` caller-এর হাতে থাকা `Metadata` নেয়, path নয়। তাই caller যে symlink follow করেনি, সেটা এখানেও follow হয় না। Panel-এর লেখা `mode_string()` helper থেকে আসে। Windows-এ `mode_bits` সবসময় `None`।
- `set_owner` symlink follow করে (`chown`), `set_link_owner` করে না (`lchown`)। Site-এর folder-এ link থাকতে পারে, তাই দুটো আলাদা।
- `create_private` নতুন file বানায়, আগে থাকলে ব্যর্থ। `replace_private` truncate করে, পুরোনো file-এর mode রেখে। `LinkPolicy::Refuse` মানে `O_NOFOLLOW`।
- `remove_file` file বা symlink মোছে, link যেখানে দেখায় সেটা নয়। Windows-এ folder-এর link folder হিসেবে মুছতে হয়, তাই `fs::remove_file` সেখানে ব্যর্থ হয়; symlink মুছতে পারে এমন code এটাই ব্যবহার করবে।
- Windows-এ ACL SDDL হিসেবে লেখা আর native API দিয়ে বসানো। `create_private` ও `create_dir_private` প্রথম মুহূর্ত থেকেই নিজের ACL নিয়ে তৈরি হয়, folder-এর ACL কখনো পায় না; নইলে secret সেখানে আসলে private থাকত না।
- Windows-এ `owner`, `set_owner` ও `set_link_owner` local profile-এ logged-in user ধরে নেয়, server-এ `Unsupported` (per-site user task J-তে)।
- Linux-এর `chown`, `chmod`, `setfacl`, `find`, `cp` command `filemanager`-এ আগের মতোই আছে; rule 4 অনুযায়ী এগুলো বদলানো আলাদা task। `Platform::one_user()` সত্য হলে (Linux ছাড়া অন্য OS-এর local profile) `filemanager` এই command-এর বদলে `platform::files()` ব্যবহার করে।

| `FileMode` | Unix | Windows |
| --- | --- | --- |
| `Private` | `0600` | শুধু owner ও SYSTEM-এর ACL |
| `PrivateDir` | `0700` | একই, inherit সহ |
| `SiteDir` | `0750` | Owner full, site group read ও execute, inherit সহ |
| `SiteFile` | `0640` | Owner full, site group read |
| `Shared` | `0644` | Owner full, site group read |
| `Executable` | `0755` | Owner full, group read ও execute |
| `Raw(n)` | `n` | File manager-এর chmod; Windows-এ `Unsupported` |

Per-site user আসার আগে (task J) Windows-এ site group মানে local `Users` group।

### 6.3 ServiceManager

```rust
pub struct ServiceStatus { pub installed: bool, pub active: bool, pub enabled: bool, pub state: String }

pub trait ServiceManager {
    fn status(&self, unit: &ServiceId) -> Result<ServiceStatus, PlatformError>;
    fn start(&self, unit: &ServiceId) -> Result<(), PlatformError>;
    fn stop(&self, unit: &ServiceId) -> Result<(), PlatformError>;
    fn restart(&self, unit: &ServiceId) -> Result<(), PlatformError>;
    fn reload(&self, unit: &ServiceId) -> Result<(), PlatformError>;
    fn enable(&self, unit: &ServiceId, on: bool) -> Result<(), PlatformError>;
    fn logs(&self, unit: &ServiceId, lines: u32) -> Result<String, PlatformError>;
}
```

Linux-এ `systemctl` ও `journalctl`। Windows ও macOS-এ `supervisor.rs`। OS service শুধু supervisor-কে চালায়।

### 6.4 AccountManager

```rust
pub trait AccountManager {
    fn create(&self, spec: &AccountSpec) -> Result<OwnerId, PlatformError>;
    fn delete(&self, name: &str, keep_files: bool) -> Result<(), PlatformError>;
    fn exists(&self, name: &str) -> Result<bool, PlatformError>;
    fn lookup(&self, name: &str) -> Result<OwnerId, PlatformError>;
    fn set_password(&self, name: &str, secret: &str) -> Result<(), PlatformError>;
    fn lock(&self, name: &str, locked: bool) -> Result<(), PlatformError>;
    fn set_shell(&self, name: &str, shell: &str) -> Result<(), PlatformError>;
    fn set_comment(&self, name: &str, comment: &str) -> Result<(), PlatformError>;
    fn add_to_group(&self, name: &str, group: &str) -> Result<(), PlatformError>;
    fn primary_group(&self, name: &str) -> Result<String, PlatformError>;
    fn home(&self, name: &str) -> Result<PathBuf, PlatformError>;
    fn shell(&self, name: &str) -> Option<String>;
    fn password_hash(&self, name: &str) -> Option<String>;
    fn list(&self, list: AccountList) -> Result<String, PlatformError>;
}

pub enum AccountList { Passwd, Shadow, Group }
```

- `AccountSpec` নতুন account-এর সব তথ্য রাখে; `site`, `login`, `restored` ও `sharing` চারটা ব্যবহার। `AccountGroup` বলে group কোনটা। Argument বানানো pure function, তাই সব OS-এ test হয়।
- `add_to_group` group না থাকলে কিছু করে না। `shell` ও `password_hash` না থাকলে `None`; `password_hash` শুধু cPanel format-এর backup-এ যায়, কখনো log হবে না।
- `list` `/etc/passwd` format-এর raw text দেয়। যেখানে account মানে user নয়, সেখানে খালি।
- `platform::accounts()` দিয়ে পাওয়া যায়। Backend ঠিক হয় OS ও profile দেখে।

| Backend | কোথায় | Implementation |
| --- | --- | --- |
| `LinuxAccounts` | Linux, সব profile | `useradd`, `userdel`, `usermod`, `groupadd`, `getent`, `id`, `chpasswd` |
| `LocalAccounts` | macOS ও Windows local | Logged-in user; account মানে `sites_root`-এর একটা folder |
| `NoAccounts` | macOS ও Windows server, যতদিন J ও K হয়নি | সব call `Unsupported`, `shell` ও `password_hash` `None` |
| পরে | Windows server | Local user API, প্রতি account-এ একটা user ও group |
| পরে | macOS server | `dscl`, `sysadminctl` |

`LocalAccounts`:

- `create` শুধু folder বানায়। `delete` folder মোছে, তাই নাম আগে যাচাই হয়: খালি, `.`, `..`, `/`, `\`, `:` ও শেষে `.` বা space থাকলে `Failed`। এই যাচাই সরানো যাবে না।
- `lookup` ও `create` `OwnerId::LOGGED_IN_USER` দেয়। এটা আসল uid নয়, তাই backup-এর manifest-এ যায় না: সেখানে `0` গেলে Linux server-এ root-এর id হয়ে যেত।
- `set_password`, `lock`, `set_shell`, `set_comment` ও `add_to_group` no-op; Spec rule 3 এখানে এই silent no-op-এর অনুমতি দেয়, কারণ local-এ সব site একই user।
- `Platform::needs_root()` হলো `!one_user()`: local PC-তে drust ইচ্ছা করেই root নয়।

### 6.5 IsolatedRunner

```rust
pub struct RunSpec {
    pub program: PathBuf, pub args: Vec<OsString>, pub env: Vec<(OsString, OsString)>,
    pub cwd: PathBuf, pub owner: Option<OwnerId>, pub limits: Option<ResourceProfile>,
    pub timeout: Option<Duration>,
}

pub trait IsolatedRunner {
    fn spawn(&self, spec: RunSpec) -> Result<ManagedChild, PlatformError>;
    fn run(&self, spec: RunSpec) -> Result<RunOutput, PlatformError>;
}
```

- Linux: বর্তমান `runuser` ও `timeout` আচরণ।
- Windows: `CreateProcessAsUser`-ধরনের API, process শুরু থেকেই Job Object-এ।
- macOS: root থেকে site user-এ `setuid` ও `setgid`, process group সহ।
- Environment সবসময় allowlist থেকে বানাতে হবে। `DRUST_API_TOKEN` কখনো child-এ যাবে না।

### 6.6 ResourceLimiter

```rust
pub struct ResourceProfile { pub cpu_percent: u32, pub memory_mb: u32, pub max_processes: u32 }
pub struct UsageSample { pub cpu_percent: f32, pub memory_mb: u64, pub processes: u32 }

pub trait ResourceLimiter {
    fn level(&self) -> LimitLevel; // Hard, Soft, None
    fn apply(&self, owner: &OwnerId, profile: &ResourceProfile) -> Result<(), PlatformError>;
    fn usage(&self, owner: &OwnerId) -> Result<UsageSample, PlatformError>;
    fn clear(&self, owner: &OwnerId) -> Result<(), PlatformError>;
}
```

| OS | Implementation |
| --- | --- |
| Linux | প্রতি account-এ systemd slice: `CPUQuota`, `MemoryMax`, `TasksMax` |
| Windows | Job Object: job-wide memory limit, CPU rate hard cap, active process limit |
| macOS | Section 10-এর supervisor loop |

### 6.7 DiskQuota

```rust
pub trait DiskQuota {
    fn level(&self) -> LimitLevel;
    fn set(&self, owner: &OwnerId, limit_mb: u64) -> Result<(), PlatformError>;
    fn usage_mb(&self, owner: &OwnerId) -> Result<u64, PlatformError>;
    fn clear(&self, owner: &OwnerId) -> Result<(), PlatformError>;
}
```

Linux: ext4/XFS quota, না থাকলে scan। Windows: NTFS per-user quota। macOS: প্রতি account-এ APFS volume।

### 6.8 FastCGI endpoint

```rust
pub enum FastCgiEndpoint { Unix(PathBuf), Tcp(SocketAddr) }
```

`connect_fpm` এই enum নেয়, আর `FastCgiStream` (একটা `AsyncRead + AsyncWrite` stream) ফেরত দেয়। Linux ও macOS: `Unix`। Windows: `Tcp`, শুধু loopback-এ। অন্য address-এ `connect` নিজেই `PermissionDenied` দেয়, কারণ PHP-র port-এ যে পৌঁছায় PHP তাকেই বিশ্বাস করে।

### 6.9 FirewallBackend

```rust
pub struct FirewallRule { pub id: String, pub action: Allow|Deny, pub port: Option<PortSpec>, pub source: Option<IpNet>, pub comment: String }

pub trait FirewallBackend {
    fn status(&self) -> Result<FirewallStatus, PlatformError>;
    fn list(&self) -> Result<Vec<FirewallRule>, PlatformError>;
    fn add(&self, rule: &FirewallRule) -> Result<(), PlatformError>;
    fn remove(&self, id: &str) -> Result<(), PlatformError>;
    fn ban(&self, ip: IpAddr, ttl: Option<Duration>) -> Result<(), PlatformError>;
    fn unban(&self, ip: IpAddr) -> Result<(), PlatformError>;
}
```

Linux: UFW ও firewalld। Windows: `NetSecurity` PowerShell module, সব rule-এর নাম `dPanel:` দিয়ে শুরু। macOS: নিজের `pf` anchor।

**Lock-out রক্ষা:** Rule প্রয়োগের পর 60 সেকেন্ডের মধ্যে panel থেকে confirm না এলে আগের অবস্থায় ফিরবে।

### 6.10 DnsBackend

```rust
pub trait DnsBackend {
    fn upsert_zone(&self, zone: &ZoneSpec) -> Result<(), PlatformError>;
    fn delete_zone(&self, name: &str) -> Result<(), PlatformError>;
    fn replace_records(&self, zone: &str, records: &[DnsRecord]) -> Result<(), PlatformError>;
    fn enable_dnssec(&self, zone: &str, on: bool) -> Result<DnssecInfo, PlatformError>;
}
```

Database-এর `DnsZone` ও `DnsRecord` সবসময় source of truth থাকবে। Backend শুধু সেটা প্রয়োগ করবে।

Implementation: PowerDNS, Windows `DnsServer` module, Cloudflare API, Local hosts file।

### 6.11 MailBackend (Laravel)

```php
interface MailBackend {
    public function ensureDomain(MailDomain $domain): void;
    public function removeDomain(MailDomain $domain): void;
    public function ensureMailbox(Mailbox $mailbox, ?string $password): void;
    public function removeMailbox(Mailbox $mailbox): void;
    public function setForwarding(Mailbox $mailbox, ?string $target): void;
    public function setAutoresponder(Mailbox $mailbox, ?AutoresponderData $data): void;
    public function dkimRecord(MailDomain $domain): DkimRecord;
    public function health(): MailHealth;
    public function queue(): MailQueueView;
}
```

`PostfixDovecotBackend`-এ বর্তমান code সরবে, আচরণ অপরিবর্তিত রেখে। IMAP-এ পড়া (`MailboxImapService`) দুই backend-এ একই থাকবে। Stalwart management API-র ঠিক কোন endpoint লাগবে, সেটা H3 spike থেকে এই section-এ যোগ হবে।

### 6.12 AcmeClient

```rust
pub trait AcmeClient {
    fn issue(&self, request: &CertRequest) -> Result<IssuedCert, PlatformError>;
    fn renew_due(&self) -> Result<Vec<RenewResult>, PlatformError>;
    fn revoke(&self, domain: &str) -> Result<(), PlatformError>;
}
```

- `CertRequest`: domains, challenge (`Http01`, `Dns01`), ACME directory URL।
- Certificate `Paths::tls_dir()`-এ `<domain>.crt` ও `<domain>.key` নামে থাকবে, যেটা gateway আগে থেকেই চেনে।
- HTTP-01 token gateway-এর memory থেকে সরাসরি দেওয়া হবে, site folder-এ file লিখে নয়।
- Renew: মেয়াদ শেষের 30 দিন আগে, দিনে একবার।
- Local profile `rcgen` দিয়ে local CA ব্যবহার করবে।

### 6.13 Scheduler

Windows ও macOS-এ crontab-এর বদলে drust-এর timer। Cron expression ও job record বর্তমান database-এই থাকবে। Job `IsolatedRunner` দিয়ে site owner হিসেবে চলবে। একই job আগের run চলাকালীন আবার শুরু হবে না। Linux-এ crontab অপরিবর্তিত।

## 7. Data model

সব migration additive। পুরোনো server-এ data হারাবে না।

| Table | নতুন column | Default |
| --- | --- | --- |
| `users` | `suspension_reason` (nullable string) | `null` |
| `websites` | `serving_state` (`active`, `suspended`, `over_limit`) | `active` |
| `package_plans` | `max_cpu_percent` (int) | `0` = সীমাহীন |
| `package_plans` | `max_memory_mb` (int) | `0` = সীমাহীন |
| `package_plans` | `max_processes` (int) | `0` = সীমাহীন |
| `package_plans` | `mail_per_hour` (int) | `0` = সীমাহীন |
| `package_plans` | `over_bandwidth_action` (`notify`, `throttle`, `block`) | `notify` |
| `package_plans` | `enforcement_mode` (`report`, `enforce`) | `report` |

নতুন table:

| Table | কাজ |
| --- | --- |
| `account_usage_samples` | `user_id`, `sampled_at`, `disk_mb`, `bandwidth_mb`, `cpu_percent`, `memory_mb` |
| `firewall_rules` | Panel-এর rule, backend-এর সঙ্গে reconcile হবে |
| `acme_orders` | Domain, challenge, status, error, `expires_at` |

`enforcement_mode=report` মানে limit ছাড়ালে শুধু report ও notification। পুরোনো server-এ upgrade-এর পর এটাই থাকবে, যাতে কেউ হঠাৎ বন্ধ না হয়।

`websites.status` provisioning-এর অবস্থা জানায়, তাই সেটা অপরিবর্তিত থাকবে। `serving_state` আলাদা: site visitor-কে দেখানো হবে কিনা।

`SiteConfig`-এ ([config.rs:21](../drust/src/edge_gateway/config.rs#L21)) নতুন field: `serving_state: SiteState`। Gateway `Suspended` ও `OverLimit` state-এ PHP না চালিয়ে `503` ও নির্দিষ্ট page দেবে। ACME challenge তখনও কাজ করবে।

## 8. drust API-র নতুন endpoint

সব endpoint-এ বর্তমান token auth।

| Method, path | কাজ |
| --- | --- |
| `GET /capabilities` | Section 5 |
| `POST /accounts/{name}/suspend`, `/unsuspend` | Mail, FTP, cron, worker, SSH বন্ধ ও চালু |
| `PUT /accounts/{name}/limits` | Resource ও disk limit |
| `GET /accounts/{name}/usage` | এখনকার usage |
| `GET`, `POST`, `DELETE /firewall/rules` | Rule |
| `POST /firewall/confirm` | Lock-out timer থামানো |
| `POST /acme/issue`, `/acme/renew` | Certificate |

Suspend idempotent: দুবার চালালেও ফল একই। Partial failure হলে কোন ধাপ ব্যর্থ হয়েছে সেটা response-এ থাকবে।

## 9. Windows php-cgi pool

প্রস্তাবিত default। H1 spike-এর পর মান ঠিক হবে।

| Setting | Default |
| --- | --- |
| Port range | `127.0.0.1:9100-9999`, প্রতি pool-এ dynamic |
| প্রতি site-এ process | কম 1, বেশি plan অনুযায়ী (default 4) |
| Process শুরু | প্রথম request-এ; 10 মিনিট idle-এ বন্ধ |
| Recycle | 500 request পরে (`PHP_FCGI_MAX_REQUESTS`) |
| Queue | সব process ব্যস্ত হলে 30 সেকেন্ড অপেক্ষা, তারপর `503` |
| Crash | Exponential backoff; 1 মিনিটে 5 বার হলে site `503` ও alert |
| Build | NTS x64 |

প্রতিটা process site user হিসেবে, সেই account-এর Job Object-এ চলবে।

এখনকার অবস্থা: `edge_gateway/php_cgi_pool.rs`, শুধু Windows local-এ (`Platform::uses_php_cgi_pool`), H1-এর আগে। সব process logged-in user হিসেবে চলে, Job Object ছাড়া। php-cgi একবারে একটা request নেয়, তাই এক process এক port, `PHP_FCGI_CHILDREN=0`। Port বাছার সময় `SO_REUSEADDR` ছাড়া bind করে দেখা হয়, কারণ php-cgi সেটা set করে আর Windows-এ তাতে দুজন একই port পেতে পারে। Child-এর environment allowlist থেকে বানানো, তাই `DRUST_API_TOKEN` PHP পায় না।

## 10. macOS supervisor limit

প্রস্তাবিত default। H5 spike-এর পর মান ঠিক হবে।

1. প্রতি 2 সেকেন্ডে প্রতি account-এর process-এর CPU ও memory মাপা।
2. CPU limit টানা 10 সেকেন্ড ছাড়ালে সেই account-এর process-এর priority কমানো।
3. টানা 60 সেকেন্ড ছাড়ালে PHP pool restart, আর `account_usage_samples`-এ event।
4. Memory limit ছাড়ালে সবচেয়ে বড় worker বন্ধ করা।
5. Process limit ছাড়ালে নতুন process আটকানো।

Package page-এ macOS-এ "soft limit" লেখা থাকবে।

## 11. Security invariant

প্রতিটা OS-এ automated test দিয়ে প্রমাণ করতে হবে:

1. Site A-র PHP site B-র file পড়তে বা লিখতে পারে না।
2. Site-এর process `drust.env`, `.env` বা `DRUST_API_TOKEN` পড়তে পারে না।
3. drust API শুধু loopback-এ, আর token ছাড়া `401`।
4. Website process admin, SYSTEM বা root হিসেবে চলে না।
5. Local profile-এর সব port শুধু `127.0.0.1`-এ। Gateway-তে `Platform::local_bind` `0.0.0.0`-কে loopback করে, অন্য address refuse করে।
6. Path traversal, symlink ও Windows junction দিয়ে site folder-এর বাইরে যাওয়া যায় না।
7. Windows-এ `C:\`, UNC, 8.3 নাম, `::$DATA` ও trailing dot path filter পার হতে পারে না।
8. Log ও error message-এ password বা token থাকে না।
