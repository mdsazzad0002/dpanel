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
  acme/           AcmeClient, SslEngine, CertInfo; client.rs-এ RFC 8555
  scheduler.rs    Scheduler
  network.rs      AddressSource: interface-এর IP ঠিকানা (U5)
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
    "redis": false, "reseller": true, "file_permissions": false, "clamav": false, "services": false,
    "waf": false
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
| `ssl` | `certbot`; `DRUST_SSL_ENGINE=acme` দিলে `acme` | `acme` | `acme` | `local_ca` |
| `firewall` | হ্যাঁ | হ্যাঁ | হ্যাঁ | না |
| `ftp` | হ্যাঁ | হ্যাঁ | হ্যাঁ | না |
| `terminal` | হ্যাঁ | না | না | হ্যাঁ |
| `docker` | হ্যাঁ | না | না | Docker Desktop থাকলে |
| `redis` | হ্যাঁ | না | হ্যাঁ | Windows-এ না, বাকিতে হ্যাঁ |
| `reseller` | হ্যাঁ | হ্যাঁ | হ্যাঁ | না |
| `file_permissions` | হ্যাঁ | না (F1 পর্যন্ত) | হ্যাঁ | Windows-এ না, বাকিতে হ্যাঁ |
| `clamav` | হ্যাঁ | না (J10 পর্যন্ত) | না (K7 পর্যন্ত) | Linux-এ হ্যাঁ, বাকিতে না |
| `services` | হ্যাঁ | না (F4 পর্যন্ত) | না (E2 পর্যন্ত) | Linux-এ হ্যাঁ, বাকিতে না |
| `waf` | `waf` module থাকলে | না (libmodsecurity দেখা হয়নি) | না (দেখা হয়নি) | না |

Laravel-এ route গুলো `capability:<name>` middleware দিয়ে আটকাবে। Sidebar ও search একই তথ্য Inertia shared prop থেকে নেবে। Capability বন্ধ থাকলে route `404` দেবে। যে feature কোনো OS-এ চলে না, সেখানে তার UI থাকে না, আর backend error না দিয়ে চুপচাপ ফিরে আসে।

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
    fn binary_or_name(&self, tool: Tool) -> PathBuf; // না পেলে নাম, যাতে error আগের মতো থাকে
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

`binary(Tool::Timeout)` Windows-এ সবসময় `NotFound`, কারণ সেখানকার `timeout.exe` শুধু console থামায়। Caller তখন time limit ছাড়া চালায় না; ClamAV `clamav` capability দিয়ে বন্ধ থাকে।

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
- `set_mode_tree(files, root, mode)` হলো `chmod -R`: folder ও তার ভেতরের সব, কিন্তু ভেতরের symlink follow করে না, কারণ সেটা system-এর যেকোনো জায়গায় দেখাতে পারে।
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
pub enum LogStyle { Timestamped, Messages, RecentMessages }

pub trait ServiceManager {
    fn status(&self, unit: &str) -> Result<ServiceStatus, PlatformError>;
    fn start(&self, unit: &str) -> Result<(), PlatformError>;
    fn restart(&self, unit: &str) -> Result<(), PlatformError>;
    fn reload(&self, unit: &str) -> Result<(), PlatformError>;
    fn reload_or_restart(&self, unit: &str) -> Result<(), PlatformError>;
    fn restart_count(&self, unit: &str) -> Result<u64, PlatformError>;
    fn enable(&self, unit: &str) -> Result<(), PlatformError>;
    fn switch(&self, units: &[&str], on: bool) -> Result<(), PlatformError>; // start+enable বা stop+disable
    fn reset_failed(&self, unit: &str) -> Result<(), PlatformError>;
    fn reload_definitions(&self) -> Result<(), PlatformError>;
    fn unit_names(&self, pattern: &str) -> Result<Vec<String>, PlatformError>;
    fn logs(&self, unit: &str, lines: u32, style: LogStyle) -> Result<String, PlatformError>;
    fn run_detached(&self, unit: &str, program: &Path, args: &[String]) -> Result<(), PlatformError>; // নিজের unit-এ, drust restart হলেও চলে
}
```

`run_detached` Linux-এ `systemd-run --unit <unit> --collect --quiet -- <program> <args>`: transient service, drust-এর environment (API token) পায় না; চলছে কিনা `status(unit)` বলে। Panel update (U6) এটা দিয়ে চলে, কারণ update drust-কেই restart করে।

`platform::services()` দিয়ে পাওয়া যায়। Linux-এ `SystemdServices` (`systemctl` ও `journalctl`)। Windows ও macOS-এ এখন `NoServices`, সব call `Unsupported`, আর `services` capability বন্ধ; E2 ও F4-এ `supervisor.rs` আসবে। OS service শুধু supervisor-কে চালাবে।

### 6.4 AccountManager

```rust
pub trait AccountManager {
    fn create(&self, spec: &AccountSpec) -> Result<OwnerId, PlatformError>;
    fn delete(&self, name: &str, keep_files: bool) -> Result<(), PlatformError>;
    fn exists(&self, name: &str) -> Result<bool, PlatformError>;
    fn lookup(&self, name: &str) -> Result<OwnerId, PlatformError>;
    fn set_password(&self, name: &str, secret: &str) -> Result<(), PlatformError>;
    fn lock(&self, name: &str, locked: bool) -> Result<(), PlatformError>;
    fn suspend(&self, name: &str, suspended: bool) -> Result<(), PlatformError>;
    fn set_shell(&self, name: &str, shell: &str) -> Result<(), PlatformError>;
    fn set_comment(&self, name: &str, comment: &str) -> Result<(), PlatformError>;
    fn end_sessions(&self, name: &str) -> Result<(), PlatformError>;
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
- `end_sessions` শুধু interactive login শেষ করে; site worker ও cron চলতে থাকে। `add_to_group` group না থাকলে কিছু করে না। `shell` ও `password_hash` না থাকলে `None`; `password_hash` শুধু cPanel format-এর backup-এ যায়, কখনো log হবে না।
- `list` `/etc/passwd` format-এর raw text দেয়। যেখানে account মানে user নয়, সেখানে খালি।
- `platform::accounts()` দিয়ে পাওয়া যায়। Backend ঠিক হয় OS ও profile দেখে।

| Backend | কোথায় | Implementation |
| --- | --- | --- |
| `LinuxAccounts` | Linux, সব profile | `useradd`, `userdel`, `usermod`, `groupadd`, `getent`, `id`, `chpasswd`, `loginctl`, `pkill` |
| `LocalAccounts` | macOS ও Windows local | Logged-in user; account মানে `sites_root`-এর একটা folder |
| `NoAccounts` | macOS ও Windows server, যতদিন J ও K হয়নি | সব call `Unsupported`, `shell` ও `password_hash` `None` |
| পরে | Windows server | Local user API, প্রতি account-এ একটা user ও group |
| পরে | macOS server | `dscl`, `sysadminctl` |

`LocalAccounts`:

- `create` শুধু folder বানায়। `delete` folder মোছে, তাই নাম আগে যাচাই হয়: খালি, `.`, `..`, `/`, `\`, `:` ও শেষে `.` বা space থাকলে `Failed`। এই যাচাই সরানো যাবে না।
- `lookup` ও `create` `OwnerId::LOGGED_IN_USER` দেয়। এটা আসল uid নয়, তাই backup-এর manifest-এ যায় না: সেখানে `0` গেলে Linux server-এ root-এর id হয়ে যেত।
- `set_password`, `lock`, `set_shell`, `set_comment`, `end_sessions` ও `add_to_group` no-op; Spec rule 3 এখানে এই silent no-op-এর অনুমতি দেয়, কারণ local-এ সব site একই user।
- `Platform::needs_root()` হলো `!one_user()`: local PC-তে drust ইচ্ছা করেই root নয়।

### 6.5 IsolatedRunner

```rust
pub trait IsolatedRunner {
    fn command(&self, owner: &str, program: &OsStr) -> Result<Command, PlatformError>;
}
```

`platform::run_as(owner, program)` দিয়ে পাওয়া যায়। Caller `Command`-এ argument, env, cwd ও stdin যোগ করে নিজে চালায়, তাই sync ও tokio দুটোই চলে।

| Backend | কোথায় | Implementation |
| --- | --- | --- |
| `RunuserRunner` | Linux | `runuser -u owner -- program` |
| `SameUserRunner` | macOS ও Windows local | সরাসরি চালায়, কারণ সব site একই user |
| `NoRunner` | macOS ও Windows server, যতদিন J ও K হয়নি | `Unsupported`: site-এর code panel-এর user হিসেবে চলবে না |
| পরে | Windows server | `CreateProcessAsUser`-ধরনের API, process শুরু থেকেই Job Object-এ |
| পরে | macOS server | root থেকে site user-এ `setuid` ও `setgid`, process group সহ |

- Environment সবসময় `INHERITED_ENV` allowlist থেকে বানানো হয়। `DRUST_API_TOKEN` কখনো child-এ যাবে না। Allowlist-এ কিছু যোগ করার আগে ভাবুন: site owner npm, composer, build script ও git hook-এ সব পড়তে পারে।
- Web terminal `runuser` সরাসরি চালায়, কারণ সেটা PTY-তে চলে; তার environment `bwrap --clearenv` পরিষ্কার করে।
- Time limit ও resource limit এখনও এখানে নেই। J2 ও P4 যোগ করবে।

### 6.6 ResourceLimiter

```rust
pub struct ResourceProfile { pub cpu_percent: u32, pub memory_mb: u32, pub max_processes: u32 }
pub struct UsageSample { pub cpu_percent: f32, pub memory_mb: u64, pub processes: u32 }

pub trait ResourceLimiter {
    fn level(&self) -> LimitLevel; // Hard, Soft, None
    fn apply(&self, group: &str, profile: &ResourceProfile) -> Result<(), PlatformError>;
    fn usage(&self, group: &str) -> Result<UsageSample, PlatformError>;
    fn clear(&self, group: &str) -> Result<(), PlatformError>;
}
```

`group` হলো panel-এর account, `a<user id>` (P4): এক account-এর কয়েকটা site user থাকতে পারে, আর package-এর limit সবার মিলিয়ে। Profile-এ `0` মানে সেই limit নেই; `cpu_percent`-এ 100 মানে এক core।

| OS | Implementation |
| --- | --- |
| Linux | প্রতি account-এ systemd slice `dpanel-a<id>.slice`: `CPUQuota`, `MemoryMax`, `TasksMax`। Account-এর site user-দের PHP নিজের PHP-FPM master-এ (`dpanel-php<v>-fpm-<owner>.service`) সেই slice-এ চলে, কারণ shared master-এর worker তার নিজের cgroup-এ থাকে |
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

Linux-এ usage সবসময় scan (sites root-এ uid-এর file, allocated block), যাতে quota ছাড়া filesystem-এও কাজ করে। `set` `setquota -u <uid> <kb> <kb> 0 0 -a` চালায়: soft ও hard একই, তাই grace period নেই। `setquota` থাকলে `level` `Hard`, না থাকলে `Soft`।

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

Rule-এর `id` তার action, port ও source-এর SHA-256-এর প্রথম 12 hex, তাই একই rule দুই backend-এ একই id পায়, আর কোথাও কিছু জমাতে হয় না। Backend শুধু নিজের লেখা ধরনের rule list করে; হাতে লেখা অন্য rule ছোঁয় না। UFW-তে deny `prepend` দিয়ে প্রথমে যায়। `ttl` এখনও রাখা হয় না।

**Lock-out রক্ষা:** Rule প্রয়োগের পর 60 সেকেন্ডের মধ্যে panel থেকে confirm না এলে আগের অবস্থায় ফিরবে। `POST`/`DELETE /firewall/rules` উত্তরে `confirm_token` ও `confirm_within_seconds` দেয়; আগে থেকে থাকা rule add করলে token `null`। `POST /firewall/confirm {"token"}` দেরিতে এলে `404`। অপেক্ষার তালিকা drust-এর memory-তে থাকে।

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

প্রথম সংস্করণ panel-এ, PHP-তে: `App\Services\Dns\Backend\DnsBackend` (`upsertZone`, `renameZone`, `deleteZone`, `replaceRecords`, `enableDnssec`, আর একটা একটা record-এর জন্য `addRecord`, `updateRecord`, `deleteRecords`, `touchZone`), `config('dns.engine')` দিয়ে বাছা। Record-এর id backend-এর (PowerDNS-এর `records.id`), কারণ `dns_records.powerdns_record_id` ও form সেটা ধরে রাখে। `addRecord`/`updateRecord`/`deleteRecords` serial ছোঁয় না; caller batch-এর শেষে একবার `touchZone` ডাকে। PowerDNS-এর gmysql table panel-এর database-এ, তাই drust-এ যাওয়ার দরকার পড়েনি; শুধু DNSSEC (`enableDnssec`, `dnssecStatus`, rectify) drust-এর `/api/v1/dns/dnssec`-এ যায়, কারণ `pdnsutil` root-এর config পড়ে। Sign করা zone (`cryptokeys`-এ row আছে) `replaceRecords`, `renameZone` ও `touchZone`-এর পরে rectify হয়; ব্যর্থ হলে log, change উল্টায় না। `deleteZone` key ও metadata-ও মোছে। PowerDNS চলে `gmysql-dnssec=yes` দিয়ে। `replaceRecords` SOA রাখে ও serial বাড়ায়; zone-এর বাইরের নাম ফেরায়।

Zone transfer (M7): `setTransfers(zone, addresses)`। Secondary-র তালিকা server-ব্যাপী, `dns_secondary_servers`-এ (শুধু IP, সর্বোচ্চ 10; DNS → Zone Transfers, শুধু admin)। এই server যে zone-এর primary, তালিকা থাকলে সেটা PowerDNS-এ `MASTER` (`NATIVE` zone-এর NOTIFY যায় না), আর প্রতিটা address-এর জন্য `domainmetadata`-এ `ALLOW-AXFR-FROM` ও `ALSO-NOTIFY`; তালিকা খালি হলে `NATIVE` ও metadata নেই। `upsertZone` নতুন zone-এ এটা নিজেই লাগায়, তালিকা বদলালে সব zone-এ। NOTIFY যায় serial বাড়লে, তাই record বদলের পরে `touchZone` লাগে। উল্টো দিক: `SLAVE` zone (`ZoneSpec.primary`, comma দিয়ে 5টা পর্যন্ত IP, `domains.master`-এ) PowerDNS নিজে primary থেকে AXFR করে; panel সেখানে SOA, NS বা অন্য record লেখে না (নিজের SOA-র serial বেশি হলে copy থামত), record বদলানো refuse করে, আর zone-কে slave করলে আগের record মোছে। Slave zone শুধু admin বানায়। PowerDNS-এর `pdns.d/transfers.conf`-এ `primary=yes` ও `secondary=yes` (4.5-এর আগে `master`/`slave`), দুই installer-ই লেখে। TSIG নেই; ACL শুধু IP দিয়ে।

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

প্রথম সংস্করণ (`App\Services\Mail\Backend\MailBackend`, `config('mail.backend')`): domain নাম string হিসেবে, আর M1-M3, M8-এর জন্য বাড়তি method।

```php
interface MailBackend {
    public function ensureDomain(string $domain): array;               // {ok, messages}
    public function ensureMailbox(Mailbox $mailbox, ?string $previousMailHome = null): void;
    public function removeMailbox(Mailbox $mailbox): void;
    public function setForwarding(Mailbox $mailbox, ?string $target): void;
    public function setAutoresponder(Mailbox $mailbox, ?AutoresponderData $data): void;
    public function supportsAutoresponder(): bool;
    public function setCatchAll(MailDomain $domain, ?string $target): void;
    public function ensureForwarder(MailForwarder $forwarder): void;
    public function removeForwarder(MailForwarder $forwarder): void;
    public function setRelay(?RelaySettings $relay): void;
    public function relay(): array;                                    // {host, port, username}
    public function setSendLimits(string $senders, string $limits): array; // P5; {ready, reason}
    public function sendLimitStatus(): array;                          // {ready, reason}
    public function deleteQueued(array $ids): int;                     // P5
    public function retryQueue(): void;
}
```

`dkimRecord`, `health` ও `queue` এখনও নেই: DKIM `MailDomainProvisioner`-এ, health `MailHealthController`-এ, queue-র তালিকা `MailDeliveryDiagnosticsService`-এ (`postqueue -p`)। P5-এ queue থেকে মোছা ও retry এসেছে। `setSendLimits`-এর দুটো map প্রতি line-এ একটা `key value`: sender (mailbox, domain বা `user:<system user>`) → account (`a<user id>`), আর account → `<প্রতি ঘণ্টা>:<প্রতি দিন>`। Postfix-এ Rspamd গোনে, Redis-এর `dpanel:mail:<account>:{h,d}:<window>` key-এ; Stalwart-এর নিজের throttle J5-এ দেখতে হবে। ব্যর্থ হলে `MailBackendException`; backend বা server না পারলে `UnsupportedMailFeature`, আর panel সেটা লুকায়।

`PostfixDovecotBackend`-এ বর্তমান code সরেছে, আচরণ অপরিবর্তিত। Postfix ও Dovecot panel-এর table সরাসরি পড়ে, তাই mailbox, forwarder ও catch-all row বদলালেই পরের mail-এ লাগে। Alias query প্রতিটা mailbox-এর জন্য নিজেকে ফেরায়, যাতে `@domain` catch-all আসল mailbox-এর mail না নেয়। Maildir ও Sieve script drust লেখে (`/api/v1/mailbox-storage`), relay `mail-smtp-relay.sh` (root, password environment-এ)। IMAP-এ পড়া (`MailboxImapService`) দুই backend-এ একই থাকবে। Stalwart management API-র ঠিক কোন endpoint লাগবে, সেটা H3 spike থেকে এই section-এ যোগ হবে।

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
- HTTP-01 token site folder-এ লেখা হয় না। Gateway আর API আলাদা process, তাই memory নয়: token যায় `data_dir/acme/.well-known/acme-challenge/<token>`-এ, আর gateway সেই folder থেকে যেকোনো hostname-এর জন্য উত্তর দেয়, site-এর IP rule, runtime বা state দেখার আগে। CA দেখা শেষ করলেই file মোছে।
- Renew: মেয়াদ শেষের 30 দিন আগে, দিনে একবার।
- Local profile `rcgen` দিয়ে local CA ব্যবহার করবে।

প্রথম সংস্করণ (I5): `platform/acme/`-এ `Rfc8555Client`, RFC 8555 নিজে লেখা (`reqwest`, `ring`-এর ES256 account key, `rcgen`-এর CSR)। External account binding নেই, তাই ZeroSSL চলে না।

- **Engine:** `platform::acme::engine()`। Linux server-এ `certbot`, `DRUST_SSL_ENGINE=acme` দিলে `acme`; অন্য server-এ সবসময় `acme`; local-এ `local_ca` (এখনও বানানো হয়নি, E4/F6)। `drust.env` gateway-ও পড়ে, তাই দুই process একই engine দেখে। `/capabilities`-এর `ssl` এই মান।
- **Setting:** `DRUST_ACME_DIRECTORY` (default Let's Encrypt production), `DRUST_ACME_EMAIL` (ঐচ্ছিক contact), `DRUST_ACME_CA_BUNDLE` (private CA-র root)। Directory ও server-এর দেওয়া প্রতিটা URL HTTPS হতে হয়; plain HTTP শুধু loopback-এ, test-এর জন্য।
- **State:** `state_dir/acme/account-<directory-র hash>.pk8` (CA প্রতি একটা account key, owner-only) আর `state_dir/acme/orders/<domain>.json` (domains, directory, challenge)। `renew_due` এই record ধরে চলে; panel-এর `acme_orders` table বানানো হয়নি।
- **Key:** প্রতি certificate-এ নতুন P-256 key। Key ও certificate আগে `.new` file-এ, তারপর rename।
- **Certbot থেকে সরা:** `acme` engine-এ ভালো certbot certificate থেকে যায় (সব site একসঙ্গে নতুন করে নিলে CA-র rate limit-এ পড়ত)। Client নেয় যখন সেটা renew-এর সময়ে আসে, নতুন site-এ, আর panel থেকে reinstall করলে। Gateway `acme` engine-এ `tls_dir`-এর certificate আগে নেয়, নয়তো certbot-এর; certbot-এর file কখনো মোছা হয় না, তাই engine ফেরালে আগের certificate-ই চলে। Certbot-এর নিজের timer আগে renew করলে site certbot-এই থাকে।
- **Certificate পড়া:** `CertInfo` (`x509-parser`) মেয়াদ ও নাম পড়ে, `openssl` program ছাড়া। Certbot-এর পথ ও IP-র self-signed certificate এখনও `openssl` চালায়।
- **Upload করা certificate (M5):** `tls_dir/custom/<domain>.crt` ও `.key`-তে, আলাদা folder-এ, তাই কোনো engine সেটা renew বা overwrite করে না, আর সরালে আগের issue করা certificate-ই আবার চলে। drust বসানোর আগে দেখে: chain-এর প্রথম certificate এই domain-এর (wildcard চলে), মেয়াদ আছে, আর key মেলে (`rustls`-এর `keys_match`, gateway যেভাবে load করে সেভাবেই; RSA, ECDSA, Ed25519, password ছাড়া)। CA trusted কিনা দেখে না। Gateway সব engine-এ এটা আগে নেয়; `www.` alias main domain-এর upload নেয় যদি সেটায় www-এর নাম থাকে। `ssl/ensure` তখন `custom: true` দিয়ে এটাই জানায়, মেয়াদ শেষ হলে error; `force` দিলে নতুন certificate issue হওয়ার পরে upload মোছে। Panel key রাখে না (`ssl_certificates.type = custom`, `auto_renew = false`), আর 30, 7 ও 1 দিন আগে ও মেয়াদ শেষে owner-কে একবার করে notification দেয় (`ssl_certificates.expiry_alert_days`, রোজের `dpanel:ssl-reconcile`-এ)।
- **Wildcard (M4):** `CertRequest.dns`-এ একটা `Dns01Solver` (`publish`, `withdraw`); `Dns01`-এ এটা বাধ্যতামূলক। Certificate `<domain>` ও `*.<domain>`, file `<domain>.crt`, প্রথম নাম কখনো wildcard নয়। দুটো authorization একই TXT নাম `_acme-challenge.<domain>` পায়, তাই একটার পর একটা: value যোগ, zone-এর নিজের nameserver (NS record থেকে, cache ছাড়া, সরাসরি) value দেখানো পর্যন্ত অপেক্ষা (সর্বোচ্চ 120 সেকেন্ড, CA-র timeout-এ ধরা হয় না), CA-কে বলা, তারপর ফল যা-ই হোক value সরানো। ওই নামের অন্য TXT থেকে যায়। Solver দুটো, `ssl/dns01.rs`-এ: `powerdns` (`pdnsutil replace-rrset`/`delete-rrset`, তারপর `rectify-zone`) ও `cloudflare` (v4 API, record id মনে রেখে ঠিক সেটাই মোছে)। `ssl/ensure`-এর `wildcard: {provider, zone, api_token?}` panel প্রতিবার পাঠায়; Cloudflare token drust কোথাও লেখে না, তাই `renew_due` DNS-01 order বাদ দেয় আর renew হয় রোজের `ssl/ensure`-এ। Wildcard সব server engine-এ নিজের client দিয়ে (certbot-এর DNS plugin নেই); local-এ refuse। Gateway: `tls_dir`-এর যে certificate `*.<domain>` নাম ধরে সেটা certbot engine-এও certbot-এর আগে; নিজের certificate নেই এমন নাম (এক label নিচে) parent-এর wildcard নেয়, upload করা certificate-ও একইভাবে। Certbot engine-এ wildcard বন্ধ করলে certbot-এর certificate ঠিক হলে client-এর file মোছে। Panel: `websites.ssl_wildcard`; zone আগে এই server-এর PowerDNS-এর `domains`-এ (নাম বা parent, লম্বাটা আগে), নয়তো `services.cloudflare.api_token` থাকলে Cloudflare-এ; কোথাও না থাকলে কিছু বদলায় না। Wildcard site-এর এক label নিচের site drust-কে না ডেকে parent-এর certificate লেখে (`wildcard_of`); `force` দিলে নিজেরটা নেয়।
- **বাইরে:** mail hostname-এর certificate (`ensure-mail-tls.sh`) ও installer-এর `issue-ssl.sh` এখনও certbot।

### 6.13 Scheduler

Windows ও macOS-এ crontab-এর বদলে drust-এর timer। Cron expression ও job record বর্তমান database-এই থাকবে। Job `IsolatedRunner` দিয়ে site owner হিসেবে চলবে। একই job আগের run চলাকালীন আবার শুরু হবে না। Linux-এ crontab অপরিবর্তিত।

```rust
pub struct CronJob { id: String, user: String, expression: String, command: String }

pub trait Scheduler {
    fn upsert(&self, job: &CronJob) -> Result<(), PlatformError>;
    fn remove(&self, id: &str) -> Result<(), PlatformError>; // না থাকলে error নয়
    fn timer_store(&self) -> Option<&Path>;                  // drust নিজে চালালে
}
```

- Linux: `CronDirScheduler`, আগের মতো `/etc/cron.d/dpanel-<id>`, পুরোনো `dpanel-<id>` মুছে দেয়।
- Windows ও macOS: `TimerScheduler`। Panel যা পাঠায় তার copy `state_dir/cron-jobs.json`-এ (owner-only, কারণ command-এ password থাকতে পারে)। Database-এর record-ই আসল; drust শুধু panel-এর পাঠানো job রাখে।
- Timer প্রতি মিনিটের শুরুতে local time-এ due job চালায়। drust বন্ধ থাকার মিনিট পরে চালানো হয় না, cron-এর মতো।
- Expression পাঁচটা সংখ্যার field: `*`, `n`, `a-b`, `a/n`, `a-b/n`, `,` তালিকা। Weekday-তে 0 ও 7 রবিবার। দুটো day field-ই সীমিত হলে যেকোনো একটা মিললেই চলে। নাম (`mon`) ও `@daily` timer-এ নেই; `upsert` তখনই refuse করে।
- Command shell-এ চলে: Unix-এ `/bin/sh -c`, Windows-এ `cmd /C`, `IsolatedRunner` দিয়ে, stdin/stdout/stderr বন্ধ। আগের run শেষ না হলে সেই job-এর নতুন run বাদ যায়।
- Panel-এর বানানো command (`schedule:run`, trash prune, WHMCS cron) `LocalCommand::shellLine`/`artisanCronLine` দিয়ে বানানো (§6.15): Linux-এ আগের string হুবহু (`cd … && php artisan … >> /dev/null 2>&1`), Windows-এ `cd /d "…" && "php.exe" artisan … >> NUL 2>&1`।

### 6.14 AddressSource (U5)

```rust
pub struct InterfaceAddress { pub interface: String, pub address: IpAddr, pub prefix: u8 }

pub trait AddressSource: Send + Sync {
    fn addresses(&self) -> Result<Vec<InterfaceAddress>, PlatformError>;
}
pub fn usable(found: Vec<InterfaceAddress>) -> Vec<ServerAddress>; // interface, address, prefix, family, public
```

Unix (Linux ও macOS): `unix/net.rs`-এ `getifaddrs`। Windows: এখনও `Unsupported { capability: IpAddresses }` (`GetAdaptersAddresses` পরে)। `usable` loopback, link-local, multicast ও unspecified বাদ দেয়, একই ঠিকানা একবার, IPv4 আগে; `public` false হয় RFC 1918, ULA, CGNAT `100.64/10` ও documentation range-এ। Panel কখনো `ip addr` চালিয়ে pool বানায় না (mail-এর পুরোনো `MailIps::localAddresses` এখনও চালায়)।

**Gateway-র সিদ্ধান্ত (U5):** gateway আগের মতো সব ঠিকানায় একটা listener-এ শোনে (`0.0.0.0`/`[::]`, dual-stack), প্রতি ঠিকানায় আলাদা bind নয়: নতুন ঠিকানা restart ছাড়াই চলে, আর site সরালে শুধু reload লাগে। প্রতিটা connection-এর local address (`LocalAddr`, `::ffff:a.b.c.d` IPv4 হিসেবে; HTTP/3-এ quinn-এর `local_ip()`) request-এ যায়, আর `select_site` ঠিক করে: ঠিকানা pool-এ না থাকলে (loopback ইত্যাদি) শুধু Host; **dedicated** ঠিকানায় শুধু সেই ঠিকানার site, অন্য Host "Site not found"; **shared** ঠিকানায় সব site (dedicated ঠিকানায় সরানো site-ও, যাতে DNS বদলানোর সময় পুরোনো ঠিকানায় চলে); bare IP (বা Host নেই) হলে ঠিকানার `default_website_id`-এর site, কিন্তু অচেনা hostname কখনো default site পায় না। Rule-গুলো `RuntimeSnapshot.ip_bindings`-এ (`IpBindings { addresses: ঠিকানা → {dedicated, default_site}, site_ips: site id → ঠিকানা }`), প্রতি full ও per-domain reload-এ `ip_addresses` ও `websites.ip_address_id` থেকে পড়া; alias তার main site-এর ঠিকানা পায়। Table না থাকলে rule খালি, অর্থাৎ আগের আচরণ। SNI ছাড়া bare IP-র HTTPS এখনও fallback certificate পায়।

### 6.15 LocalCommand (Laravel, D4)

Panel যে machine-এ চলে সেখানে program চালানোর একমাত্র পথ: `App\Support\Platform\LocalCommand`।

```php
public function run(array $argv, ?string $cwd = null, ?int $timeout = 60, array $env = [], ?string $input = null): ProcessResult;
public function output(array $argv, int $timeout = 15): ?string;   // shell_exec(... 2>/dev/null)-এর মতো: stdout, কিছু না থাকলে null
public function combinedOutput(array $argv, int $timeout = 15): string; // stdout ও stderr (আগের "2>&1")
public function succeeds(array $argv, int $timeout = 15): bool;
public function find(string $program): ?string;                     // PATH খোঁজা, Windows-এ PATHEXT; `command -v`-এর বদলে
public function supports(string $tools): bool;                      // UNIX_TOOLS, LINUX_TOOLS, SYSTEMD, POSTFIX
public function shellLine(array $argv, ?string $cwd = null, bool $discardOutput = false): string;
public function artisanCronLine(array $arguments): string;
public function phpCli(string $version): string;                    // Linux-এ /usr/bin/phpX.Y, অন্য OS-এ PHP_BINARY
public function routeSource(string $destination): string;           // Linux-এ `ip route get`-এর src
```

- Command সবসময় argv list; shell নেই, pipe ও `grep`/`head`/`awk`-এর কাজ PHP-তে। Laravel-এর `Process` দিয়ে চলে, তাই test-এ `Process::fake`।
- `supports()` host-এর tool জানায়, যে OS-এ PHP চলে সেখান থেকে (drust-এর `/capabilities` নয়): `UNIX_TOOLS` Linux ও macOS (`sh`, `ps`, `id`, `hostname`, `sudo`); `LINUX_TOOLS` (`/proc`, `ip`, `ss`, `nproc`, `lscpu`, `dpkg`, `dmidecode`, `ufw`, `sshd`, `useradd`), `SYSTEMD` ও `POSTFIX` শুধু Linux। না থাকলে caller কিছু না চালিয়ে খালি বা `unknown` উত্তর দেয়।
- `shellLine` শুধু এমন string-এর জন্য যা অন্য program পরে shell-এ চালায় (cron, §6.13)। Unix-এ সাধারণ শব্দ quote ছাড়া (তাই Linux-এর পুরোনো line বদলায় না), বাকি single quote; Windows-এ double quote, আর `"`, `%`, `!` বা newline থাকলে refuse।
- Test-এ অন্য OS: `new LocalCommand('Windows')` container-এ bind করা।
- MCP (D5): managed server SSH দিয়ে, তাই সবসময় Linux-এর নিয়ম। Panel host-এ (`server_id=local`, `mcp-exec.sh`, Windows-এ Git bash) `McpCommandPolicy::evaluate(..., panelHost: true)` host-এর OS মানে: read-only token-এর `systemctl`/`journalctl`/`hostnamectl` লাগে `SYSTEMD`, `free`/`lscpu`/`nproc`/`vmstat`/`cat /etc/` ইত্যাদি `LINUX_TOOLS`, `uptime`/`ps aux` `UNIX_TOOLS`; না থাকলে refuse। Windows-এ আরও: `\` কে `/` ধরে sensitive path (`dpanel/config`, `drust.env`, `system32/config`, Windows credential), আর সব token-এর জন্য blocked: drive root মোছা (`rm`/`rd`/`del`/`Remove-Item` `C:\`), `format C:`, `diskpart`, `bcdedit`, `vssadmin`, `net user`, `reg delete`, firewall বন্ধ, drive root-এ `icacls`। `get_service_status`, `restart_service`, `read_service_logs` panel host-এ `SYSTEMD` ছাড়া error দেয় (`McpCommandExecutor::requireHostTools`)। Windows-এ `read_log_file` drive path নেয়, `mcp.windows_log_roots` (খালি হলে `%ProgramData%\dPanel\logs`)। Opt-in file `LocalHostAccess::configPath()`: Linux `/etc/dpanel/mcp-local.conf`, Windows `%ProgramData%\dPanel\config\mcp-local.conf`, macOS `/Library/dPanel/config/mcp-local.conf`।

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
| `users` | `terminated_at`, `purge_after` (nullable timestamp) | `null` |
| `users` | `disk_over_limit_at` (nullable timestamp), `bandwidth_over_limit_month` (nullable `Y-m`) | `null` |
| `mail_domains` | `catch_all_to` (nullable string) | `null` |
| `ssl_certificates` | `expiry_alert_days` (nullable int: শেষ পাঠানো সতর্কতা, 30, 7, 1 বা 0) | `null` |
| `users` | `is_service_account` (boolean; extension-এর service account, `HumanUserScope` সব Eloquent query থেকে বাদ দেয়, তাই login করতে পারে না; fillable নয়) | `false` |
| `websites` | `ip_address_id` (nullable, `ip_addresses.id`; U5) | `null` = main IP |

নতুন table:

| Table | কাজ |
| --- | --- |
| `account_usage_samples` | `user_id`, `sampled_at`, `disk_mb`, `bandwidth_mb`, `cpu_percent`, `memory_mb` |
| `usage_alerts` | `user_id`, `resource` (`disk`, `bandwidth`, `memory`, `cpu`), `period` (bandwidth-এ মাস), `level` (80 বা 100), `notified_at`; প্রতি account ও resource-এ একটা (P6) |
| `firewall_rules` | Panel-এর rule, backend-এর সঙ্গে reconcile হবে |
| `acme_orders` | Domain, challenge, status, error, `expires_at`. এখনও নেই: I5 renewal-এর record drust-এর `state_dir/acme/orders/`-এ রাখে, আর অবস্থা আগের মতো `domains` ও `ssl_certificates`-এ |
| `website_protected_directories` | `website_id`, `path`, `realm`, `username`, `password_hash` (`s256i$<rounds>$<salt hex>$<hex>`) |
| `website_guard_settings` | `website_id`, `no_php_paths`, `denied_paths` (কমা দিয়ে path prefix; folder ও তার ভেতরের সব request `403`, ACME challenge বাদে; app installer app-এর `.htaccess`-এর deny-all folder এখানে লেখে, নতুন app install করলে list বদলে যায়), `denied_extensions` (কমা দিয়ে file type: পুরো site-এ `tpl`, বা একটি folder-এ `/modules/*.tpl`; `403`, `robots.txt` ও `/.well-known/` বাদে; app-এর `<FilesMatch>` deny থেকে installer লেখে, `denied_paths`-এর মতো বদলায়। এর বাইরে প্রতিটি site-এ dot দিয়ে শুরু হওয়া path segment (`.env`, `.git/`, `.htaccess`) সবসময় `403`; `/.well-known/` সবসময় খোলা, শুধু তার ভেতরের dot-segment বাদে), `hotlink_protection`, `hotlink_allowed_hosts`, `cloudflare_only`, `waf_mode` (`off`, `detect`, `block`; row না থাকলে `detect`) |
| `wordpress_stagings` | `production_website_id`, `staging_website_id` (দুটোই unique), `clone_id`, `direction` (`to_staging`, `to_production`, `safe_update`), `stage`, `message`, `copied_at`, `deployed_at` |
| `mail_forwarders` | `address` (unique), `domain`, `destinations` (কমা দিয়ে), `status`, `created_by`, `assigned_reseller_id` |
| `mailbox_autoresponders` | `mailbox_id` (unique), `enabled`, `subject`, `body`, `interval_days`, `starts_on`, `ends_on` |
| `brandings` | `owner_id` (null = server, নয়তো reseller; unique), `panel_name`, `logo_path`, `primary_color` |
| `dns_secondary_servers` | `address` (IP, unique), `label`; এই server-এর zone যারা copy করে (M7) |
| `restic_snapshots` | `website_id`, `snapshot_id` (unique), `taken_at`, `files_new`, `files_changed`, `data_added`, `total_bytes`, `warning`; repository-র restore point-এর প্রতিচ্ছবি, `sync` repository থেকে আবার বানায় (W6) |
| `restic_restore_tests` | `trigger` (`schedule`/`manual`), `status` (`running`/`passed`/`failed`), `started_at`, `finished_at`, `repository_healthy`, `repository_message`, `read_data_percent`, `websites_tested`, `websites_failed`, `results` (JSON, website প্রতি: `snapshot_id`, `ok`, `problem` `missing`/`unreadable`/`damaged`/`stale`, `error`, file ও database-এর হিসাব), `message`; restore test-এর report, শেষ 52টা থাকে (W8) |
| `api_tokens` | `user_id`, `name`, `token_prefix`, `token_hash` (SHA-256, unique), `scopes` (JSON: `websites:read` ইত্যাদি), `last_used_at`, `last_used_ip`, `expires_at`; revoke করলে row মোছে (U1) |
| `ip_addresses` | IP pool (U5): `address` (unique, interface-এর ঠিকানা, gateway যা দেখে), `public_address` (NAT-এর public ঠিকানা, DNS-এ যায়), `interface`, `label`, `mode` (`shared`, `dedicated`), `reseller_id`, `user_id` (dedicated-এর মালিক; customer-এর ঠিকানায় তার reseller-ও থাকে), `is_main` (একটা, সবসময় shared), `default_website_id` (bare IP-তে কোন site)। ব্যবহার: shared সবার; `user_id` থাকলে শুধু সেই user-এর website; শুধু `reseller_id` থাকলে সেই reseller-এর website, আর বাছতে পারে শুধু admin বা সেই reseller। Admin-এর জন্যও একই নিয়ম। Mail-এর `mail_ips` আলাদা থাকে; pool-এ dedicated ঠিকানা শুধু মালিকের domain-এর mail পাঠায় |
| `panel_updates` | drust-এর update run-এর copy (U6): `run_id` (unique), `action` (`update`, `rollback`), `status`, `step`, `target_ref`, `from_version/ref/commit`, `to_version/ref/commit`, `rollback_of`, `restore_database`, `has_backup`, `message`, `user_id`, `started_at`, `finished_at`, `notified_at`। আসল অবস্থা drust-এর `state_dir/panel-update/<id>/`-এ; rollback database ফেরালে row drust থেকে আবার আসে |
| `extensions` | Install করা extension (U7, [extension-spec.md](extension-spec.md)): `identifier` (manifest-এর `id`, unique), `name`, `version`, `description`, `author`, `homepage`, `manifest` (JSON), `granted_scopes` (JSON, admin-এর অনুমোদন), `events` (JSON, scope দিয়ে ছাঁটা), `ui_title`, `ui_url`, `webhook_url`, `webhook_secret` (encrypted), `enabled`, `service_user_id` (cascade), `installed_by`, `last_delivery_at`, `last_delivery_status`, `last_delivery_error`। Token U1-এর `api_tokens`-এ, owner service account |

`throttle` এখনও নেই: gateway rate কমাতে পারে না, তাই panel শুধু `notify` ও `block` দেয়। `block`-এ site `over_limit` হয়, নতুন মাসে ফেরে।

`terminate` করলে account suspend হয় আর `purge_after` 30 দিন পরে বসে। এর মধ্যে unsuspend করলে দুটোই মোছে। সময় পেরোলে প্রতিটা website আগে trash-এ archive হয়, তারপর মোছে।

Alias row-এর নিজের `website_guard_settings` বা protected directory নেই: gateway main site-এর (`parent_id`) row পড়ে।

`enforcement_mode=report` মানে limit ছাড়ালে শুধু report ও notification। পুরোনো server-এ upgrade-এর পর এটাই থাকবে, যাতে কেউ হঠাৎ বন্ধ না হয়।

`websites.status` provisioning-এর অবস্থা জানায়, তাই সেটা অপরিবর্তিত থাকবে। `serving_state` আলাদা: site visitor-কে দেখানো হবে কিনা।

`SiteConfig`-এ ([config.rs:21](../drust/src/edge_gateway/config.rs#L21)) নতুন field: `serving_state: SiteState`। Gateway `Suspended` ও `OverLimit` state-এ PHP না চালিয়ে `503` ও নির্দিষ্ট page দেবে। ACME challenge তখনও কাজ করবে। Redirect ও edge cache-ও `dispatch`-এর আগে চলে, তাই সেগুলো বন্ধ site-এর জন্য বাদ যায়।

## 8. drust API-র নতুন endpoint

সব endpoint-এ বর্তমান token auth।

| Method, path | কাজ |
| --- | --- |
| `GET /capabilities` | Section 5 |
| `POST /accounts/{name}/suspend`, `/unsuspend` | Mail, FTP, cron, worker, SSH বন্ধ ও চালু |
| `PUT /accounts/{name}/limits` | Resource ও disk limit; এখন শুধু `{"disk_mb": n}`, `0` limit তুলে নেয় |
| `GET /accounts/{name}/usage` | এখনকার usage: `disk_mb`, `disk_limit_level` (`hard`, `soft`, `none`) |
| `PUT /resource-limits/{group}` | `{"cpu_percent", "memory_mb", "max_processes", "owners": [site user]}`; সব `0` হলে limit সরায়। Site user-দের PHP slice-এ যায়, তালিকা থেকে বাদ পড়া user shared master-এ ফেরে (P4) |
| `DELETE /resource-limits/{group}` | সব site user shared master-এ, slice-এর limit সরানো |
| `GET /resource-limits/{group}/usage` | `cpu_percent` (আগের call থেকে গড়), `memory_mb`, `processes`, `limit_level`; limit না পারলে `501` |
| `GET`, `POST`, `DELETE /firewall/rules` | Rule |
| `POST /firewall/confirm` | Lock-out timer থামানো |
| `POST /api/v1/ssl/ensure`, `/api/v1/ssl/remove` | আগের endpoint, দুই engine-এ একই request ও response; panel-এর কিছু বদলায়নি (I5) |
| `GET`, `PUT /api/v1/dns/dnssec/{zone}` | `{"enabled"}`; উত্তর `{enabled, ds: [DS line]}`। `pdnsutil`: ECDSA P-256 CSK, narrow NSEC3 `1 0 0 -`, তারপর rectify; PowerDNS ছাড়া `501` (M6) |
| `POST /api/v1/dns/dnssec/{zone}/rectify` | Sign করা zone-এর record বদলের পরে `auth` ও empty non-terminal ঠিক করা (M6) |
| `POST /api/v1/ssl/install` | Customer-এর নিজের certificate (M5): `{domain, certificate, private_key}`; উত্তর `ssl/ensure`-এর মতো, সঙ্গে `custom: true` ও `names` |
| `POST /api/v1/restic/init`, `/backup`, `/snapshots`, `/forget` | Incremental backup (W6). প্রতিটায় `{repository, password, env}`; drust কিছু লেখে না। `repository`: absolute folder (sites root-এর বাইরে) বা `sftp:`, `s3:`, `rest:`, `rclone:`, `b2:`; `-` দিয়ে শুরু নয়; `env` শুধু `AWS_*` ও `B2_*`-এর পাঁচটা নাম। restic চলে root হিসেবে, environment খালি করে, password `RESTIC_PASSWORD`-এ, cache `state_dir/restic-cache`-এ। `init` আগে `cat config`: খোলে তো `created: false`, ভুল password তো error (নতুন repository কখনো নয়), নেই তো `init`। `backup` `{website: {id, domain, site_owner, databases}}`: site user-এর home আর প্রতিটা database-এর dump (`state_dir/restic-staging/<id>/databases/`, owner-only, পরে মোছা) এক snapshot-এ, tag `dpanel`, `website:<id>`, `owner:<owner>`, host `dpanel`, `--exclude-caches`; exit 3 (কিছু file পড়া যায়নি) snapshot ধরে, `warning` দিয়ে। `forget` `{keep: {daily, weekly, monthly}}`, `--group-by host,tags`, কিছু গেলে তবেই `prune`; উত্তর `removed` id। Restore W7-এর। |
| `POST /api/v1/restic/ls`, `/restore` | Restore point থেকে ফেরানো (W7)। দুটোতেই `{repository, password, env, snapshot_id, website: {id, site_owner, databases}}`; আগে `snapshots --json <id>`-এর tag-এ `website:<id>` না থাকলে refuse। Path শুধু site home-এর ভেতরে, absolute, `.`/`..` ছাড়া। `ls {path}`: সেই folder-এর সরাসরি ভেতরের node। `restore {item}`: `files {path}` → `restored-<stamp>/<নাম>` (file হলে raw `dump`, folder হলে `dump --archive tar`); `mailbox {path}` (home/mail/…/Maildir, এখন থাকতে হবে) → Maildir-এর নতুন `.Restored-<stamp>` folder (আর `.Restored-<stamp>.<Sub>`), শুধু `cur`/`new`-এর message, প্রতিটায় `cur`/`new`/`tmp` ও `maildirfolder`; `database {name}` (শুধু `databases`-এ যা আছে) → আগে এখনকার dump `restored-<stamp>/before-restore/`-এ, সেটা না হলে import হয় না, তারপর snapshot-এর dump `mysql`-এ। restic root হিসেবে পড়ে; root restic-এর tar নতুন করে লেখে (শুধু file, folder, symlink; owner নয়; প্রতিটা entry নতুন জায়গায়, বাইরে যাওয়া entry-ও restore folder-এর ভেতরে), আর লেখে site user হিসেবে চলা `tar -x -k --no-same-owner` (`IsolatedRunner`), তাই home-এর link root-কে অন্য কোথাও লেখাতে পারে না আর কিছু overwrite হয় না। |
| `POST /api/v1/restic/check`, `/verify` | Restore test (W8); কিছুই লেখে না। `check {read_data_percent}`: `restic check --retry-lock 10m` (0-র বেশি হলে `--read-data-subset=<n>%`); repository-র ক্ষতি error নয়, `{healthy: false, message}`; খুলতে না পারলে (ভুল password) error। `verify {snapshot_id, website: {id, site_owner}, sample}`: W7-এর মতো আগে tag যাচাই; `ls`-এর home-এর সব file গোনে, path ধরে সাজিয়ে সমান দূরত্বে `sample`টা (সর্বোচ্চ 100, 256 MB-র বড় file বাদ, `too_large_to_sample`-এ গোনা) `dump` করে পড়ে, byte ও recorded `size` মেলাতে হয়; staging-এর `databases/`-এর প্রতিটা dump পুরোটা পড়ে, size মিলতে হয় ও শেষ 1 KB-তে `-- Dump completed` থাকতে হয়। উত্তর `{ok, files, bytes, too_large_to_sample, sampled[], databases[]}`। Panel test চালায় backup-এর পরে; `backup` এখন `--retry-lock 30m` দেয়, যেন হাতে চালানো `check`-এর lock-এ ব্যর্থ না হয়। |
| `POST /api/v1/ssl/renew` | `AcmeClient::renew_due`: due certificate প্রতি `{domain, renewed, error}`; `certbot` engine-এ কিছু করে না |
| `GET /sites/logs?kind=access\|waf\|php&domain=&owner=&lines=` | Site-এর access log, WAF-এর match (S2) বা account-এর PHP error log-এর শেষ অংশ (S4) |
| `POST /api/v1/wordpress` | নির্দিষ্ট wp-cli action, site user হিসেবে: `info`, `update_core`, `update_plugins`, `update_themes`, `security`, `fix`, `home_url`, `search_replace` (W1-W4) |
| `GET /system/overview?services=`, `GET /system/services?services=` | Machine-এর সংখ্যা ও service-এর অবস্থা (D3) |
| `GET /system/addresses` | Interface-এর ব্যবহারযোগ্য IP ঠিকানা `{addresses: [{interface, address, prefix, family, public}]}`; Windows-এ `501` (U5, §6.14) |
| `GET`, `POST /api/v1/panel-update/runs` | Panel update-এর run-এর তালিকা `{active, runs}`; `POST` `{"action": "update", "target_ref", "repo"}` বা `{"action": "rollback", "from_run", "restore_database"}` `dscript/scripts/panel-update.sh`-কে `run_detached` দিয়ে `dpanel-update-<id>` unit-এ চালায়। একটা চলার সময় `409`, git checkout-এ `400`, systemd ছাড়া `501` (U6) |
| `GET /api/v1/panel-update/runs/{id}?offset=` | একটা run-এর `state` আর `offset` থেকে log (সর্বোচ্চ 256 KB); unit না থাকলে চলমান run `interrupted` (U6) |
| `POST /api/v1/mailbox-storage` `action=autoresponder` | Mailbox-এর Sieve `vacation` script লেখা বা (`autoresponder: null`) মোছা; drust script বানায়, panel শুধু subject, body, days, তারিখ পাঠায় (M1) |

Suspend idempotent: দুবার চালালেও ফল একই। Partial failure হলে কোন ধাপ ব্যর্থ হয়েছে সেটা response-এ থাকবে।

এখন `/accounts/{name}/suspend` শুধু system account-এর login বন্ধ করে: `AccountManager::suspend` Linux-এ `usermod --expiredate 1` দেয় (মেয়াদ শেষ account PAM-এ আটকায়, তাই SSH key-ও কাজ করে না) আর `end_sessions` চালায়; `/unsuspend` মেয়াদ মুছে দেয়। Uid 1000-এর নিচে ও `nobody` কখনো suspend হয় না। Session, website, cron ও worker panel নিজেই বন্ধ করে (`AccountSuspensionService`)। Local profile-এ কিছু করে না, account ছাড়া server-এ `501`।

### 8.1 Panel-এর public API (U1)

drust-এর নয়, panel-এর নিজের API: `routes/api.php`, prefix `/api/v1`, `Authorization: Bearer dp-api-...` (`api_tokens`)। Session, cookie, CSRF নেই। Token তার owner হিসেবে চলে: প্রতিটা endpoint panel-এর একই `visibleTo` ও package limit মানে। Owner suspended বা terminated হলে `403`। Scope: `websites`, `dns`, `databases`, `mail`, প্রতিটার `:read` ও `:write` (`write`-এ `read`-ও চলে); owner-এর role বা permission সেই area-র panel route-এর মতো না হলে scope-টা `403` দেয়। Error সবসময় JSON `{message, errors?}`; token প্রতি মিনিটে 120 request (`api-v1` limiter)। `GET` ছাড়া সফল request activity log-এ `api.request`। পুরো বর্ণনা `dpanel/resources/api/openapi.yaml` (OpenAPI 3), `GET /api/v1/openapi`-এ token ছাড়া; `ApiOpenApiTest` দেখে route আর document মেলে।

| Method, path | Scope | কাজ |
| --- | --- | --- |
| `GET /api/v1/me` | - | Token ও owner |
| `GET`, `POST /api/v1/websites`; `GET`, `DELETE /api/v1/websites/{id}` | `websites` | `MainWebsiteController`-এর `store`/`destroy` দিয়ে; main ও sub |
| `GET /api/v1/dns/zones`; `GET`, `POST /api/v1/dns/zones/{zone}/records`; `PUT`, `DELETE .../records/{id}` | `dns` | `DnsRecordService` দিয়ে (DNS page-ও এটা ব্যবহার করে); record id = PowerDNS id; SOA বাদ |
| `GET`, `POST /api/v1/databases`; `DELETE /api/v1/databases/{id}` | `databases` | `DatabaseController::provision`; delete শুধু panel-এর row মোছে, page-এর মতো |
| `GET`, `POST /api/v1/mailboxes`; `PUT /api/v1/mailboxes/{id}/password`; `DELETE /api/v1/mailboxes/{id}` | `mail` | `EmailController`-এর `store`/`deleteMailbox`; শুধু নিজের website-এর domain-এ |

### 8.2 Extension (U7)

পুরো নকশা [extension-spec.md](extension-spec.md)। Extension-এর নিজের `/api/v1` route নেই: U1-এর endpoint নিজের token দিয়ে ডাকে। Token-এর owner service account হলে `AuthenticateApiToken` দেখে তার extension চালু কিনা, নয়তো `403 This extension is disabled.`। Panel-এর admin route (session, `role:admin`):

| Method, path | কাজ |
| --- | --- |
| `GET /system/extensions` | Page: install করা extension |
| `POST /system/extensions/review` | `{manifest}` যাচাই; উত্তর `{manifest, grantable, installed}`, ভুলে `422` `errors.manifest` |
| `POST /system/extensions` | `{manifest, scopes}`: নতুন `id` হলে install (`201`, token ও secret একবার), থাকলে update; `scopes` ⊆ manifest ∩ admin-এর scope, নয়তো `422` |
| `PUT /system/extensions/{id}/state` | `{enabled}`; বন্ধ হলে token `403`, event যায় না, page `404` |
| `POST /system/extensions/{id}/credentials` | নতুন token ও webhook secret, পুরোনো token মোছে |
| `POST /system/extensions/{id}/ping` | Signed `ping` এখনই পাঠায়; ব্যর্থ হলে `502` |
| `DELETE /system/extensions/{id}` | Token, row ও service account মোছে |
| `GET /system/extensions/{id}/page` | Admin page: `sandbox="allow-scripts allow-forms allow-popups"` iframe, header `Content-Security-Policy: frame-src <origin>; frame-ancestors 'self'`, URL-এ signed `dpanel_context` |

Webhook: `POST` JSON, `X-Dpanel-Signature: sha256=<HMAC-SHA256("<timestamp>.<body>", secret)>`, redirect নয়, 3 বার চেষ্টা; event এখন `website.created`, `website.deleted`।

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

এখনকার অবস্থা: `edge_gateway/php_cgi_pool.rs`, শুধু Windows local-এ (`Platform::uses_php_cgi_pool`), H1-এর আগে। সব process logged-in user হিসেবে চলে। সবাই drust-এর একটা Job Object-এ (`JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE`, কোনো limit নেই), তাই drust মরলে php-cgi-ও থামে। php-cgi একবারে একটা request নেয়, তাই এক process এক port, `PHP_FCGI_CHILDREN=0`। Port বাছার সময় `SO_REUSEADDR` ছাড়া bind করে দেখা হয়, কারণ php-cgi সেটা set করে আর Windows-এ তাতে দুজন একই port পেতে পারে। Child-এর environment allowlist থেকে বানানো, তাই `DRUST_API_TOKEN` PHP পায় না।

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
