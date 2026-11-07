# dPanel Native Windows ও macOS Plan

WSL বা কোনো Linux VM ছাড়া dPanel সরাসরি Windows ও macOS-এ চালানোর plan।

তারিখ: 2026-11-07। Feature-এর plan আলাদা: [feature-gap-plan.md](feature-gap-plan.md)।

## সংক্ষেপে

**হ্যাঁ, করা সম্ভব, তবে local development tool হিসেবে** (XAMPP, Laragon বা Laravel Herd-এর মতো)। Windows বা Mac-এ public hosting server হিসেবে নয়।

কারণ dPanel-এর hosting অংশ Linux-এর ওপর দাঁড়ানো: প্রতি site-এ আলাদা Linux user, systemd, Postfix/Dovecot, PowerDNS, UFW, Fail2ban। এগুলোর Windows/macOS সংস্করণ বানানো মানে আলাদা একটা পণ্য বানানো। Plesk Windows-এ চলে, কিন্তু সেখানে IIS-এর ওপর আলাদা hosting stack লাগে।

| বিষয় | সিদ্ধান্ত |
| --- | --- |
| লক্ষ্য | একটা installer: panel, PHP, MariaDB ও gateway সব নিজে থেকে চলবে |
| ক্রম | আগে macOS, তারপর Windows |
| মূল কৌশল | drust-এ platform layer, আর drust নিজেই সব service চালাবে |
| WSL সংস্করণ | Native স্থিতিশীল না হওয়া পর্যন্ত থাকবে, তারপর বন্ধ |
| সময় | ১ জন dev: মোটামুটি 5-6 মাস (অনুমান) |

## মাপা হয়েছে: drust compile হয় কিনা

Linux থেকে `cargo check` চালিয়ে দেখা হয়েছে। তিনটা শর্তে:

- `--no-default-features`, তাই OCR বন্ধ।
- C compiler stub করা, তাই `ring`, `aws-lc-sys`-এর C অংশ আসলে build হয়নি।
- Windows-এ `whisper-rs` stub করা, কারণ এটা cross-compile-এ fail করেছে। macOS-এ বাঁধা bindings দিয়ে পাস করেছে।

| Target | Result |
| --- | --- |
| macOS (`aarch64-apple-darwin`) | **0 error**, পুরো type-check পাস |
| Windows (`x86_64-pc-windows-gnu`) | **60 error, 20টা file-এ** |

Windows-এর সব error Unix-only API থেকে: `std::os::unix`, `UnixStream`, আর permission ও uid-এর method। সবচেয়ে বেশি `api/file_sync.rs` (6), `filemanager/upload.rs` (5), `pgadmin_sso.rs`, `installer/service.rs`, `filemanager/unzip.rs`, `edge_gateway/terminal_ws.rs` (4 করে)।

**এই result-এর সীমা:**

- এটা শুধু compile-এর প্রমাণ। Link হয়নি, চালানো হয়নি।
- Windows-এর error ঠিক করলে নতুন error বেরোতে পারে।
- আসল Windows ও Mac machine-এ C dependency build এখনো পরীক্ষা হয়নি। `whisper-rs` Windows-এ build হবে কিনা জানা নেই।
- MSVC target (`x86_64-pc-windows-msvc`) পরীক্ষা হয়নি, অথচ release সম্ভবত সেটাতেই হবে।

## আসল কাজ কোথায়

Compile সহজ অংশ। চলার সময় Linux-নির্ভরতাই মূল কাজ:

| জিনিস | পরিমাণ |
| --- | --- |
| drust-এ `Command::new` (`id`, `tar`, `systemctl`, `mysql`, `chown`, `useradd`, `runuser`...) | 174 |
| drust-এ hardcoded Linux path (`/var/www`, `/usr/bin`, `/etc/...`) | প্রায় 257টা (string search থেকে) |
| OS-নির্ভর command বা path ছোঁয়া drust file | 144-এর মধ্যে 53 |
| Laravel-এ OS-নির্ভর file (`posix_*`, `shell_exec`, `Process::`, `/etc/`) | 470-এর মধ্যে 36 |
| `posix_geteuid` call | 14 (Windows-এ `ext-posix` নেই, তাই fatal error হবে) |
| `dscript` shell script | 109 file, প্রায় 11,000 লাইন (desktop-এ ব্যবহার হবে না) |

ভালো খবর: Laravel drust-এর সঙ্গে HTTP দিয়ে কথা বলে, আর composer-এ `ext-posix`/`ext-pcntl` বাধ্যতামূলক নয় (শুধু queue worker-এর সব feature-এর জন্য suggest করা)। Vue frontend-এ কোনো পরিবর্তন লাগে না, শুধু page লুকানো ছাড়া।

## Architecture

```text
   Tray app (Tauri)  ──  Start / Stop / Open / Sites
          │
          ▼
   dpanel-desktop service   (Windows Service / macOS LaunchAgent)
          │  drust supervisor: start, restart, log
          ├── edge gateway    :80 / :443
          ├── drust API       127.0.0.1:9500
          ├── PHP             macOS: php-fpm │ Windows: php-cgi pool
          ├── MariaDB         127.0.0.1
          ├── queue worker    php artisan queue:work
          └── scheduler       cron-এর বদলে drust-এর নিজের timer
```

### মূল সিদ্ধান্ত

**1. একটাই supervisor।** systemd-এর বদলে drust নিজেই child process চালাবে ও restart করবে। তাহলে Windows Service ও launchd-এর জন্য শুধু একটা ছোট wrapper লাগে, প্রতিটা service-এর জন্য আলাদা OS integration নয়।

**2. Platform layer।** `drust/src/platform/` নামে একটা module, আর `linux.rs`, `macos.rs`, `windows.rs`:

| Trait | Linux | Desktop |
| --- | --- | --- |
| `ServiceManager` | systemctl | Supervisor |
| `AccountManager` | useradd, chown, runuser | No-op: সব site logged-in user-এ চলে |
| `Paths` | `/var/www`, `/home/<user>` | `%LOCALAPPDATA%\dPanel`, `~/Library/Application Support/dPanel` |
| `FastCgiEndpoint` | Unix socket | macOS: Unix socket, Windows: TCP |
| `Permissions` | chmod, setfacl | Windows-এ no-op |
| `CertificateIssuer` | certbot | Local CA |

Linux-এর আচরণ যেন না বদলায়, তাই code একটা একটা করে এই layer-এ সরান, প্রতি ধাপে পুরোনো test চালিয়ে।

**3. Capability flag।** drust একটা `/capabilities` endpoint দেবে, আর Laravel-এ `PlatformCapabilities` service থাকবে। Mail, DNS, firewall-এর মতো page desktop-এ menu ও route থেকেই লুকানো থাকবে। আলাদা Vue build লাগবে না।

**4. কম অধিকার।** Service যতটা সম্ভব সাধারণ user হিসেবে চলবে, SYSTEM বা root হিসেবে নয়। সব port শুধু `127.0.0.1`-এ bind হবে। Admin অধিকার লাগবে শুধু install, hosts file আর local CA trust-এর জন্য।

এটা জরুরি, কারণ Linux-এ drust root হিসেবে চলে। সেই নকশা Windows-এ SYSTEM হিসেবে হুবহু চালালে একটা local privilege escalation-এর পথ তৈরি হবে।

## Feature: desktop-এ কী থাকবে

| Feature | Desktop | মন্তব্য |
| --- | --- | --- |
| Website, একাধিক PHP version | ✅ | Windows-এ php-cgi |
| Laravel/WordPress installer, app installer | ✅ | |
| MariaDB, phpMyAdmin | ✅ | সঙ্গে দেওয়া হবে |
| File manager | ✅ | Windows path ও permission আলাদা |
| Git deploy, Node, Python | ✅ | Process মডেল বদলাতে হবে |
| Backup, restore | ✅ | `tar`, `mysqldump` পাল্টাতে হবে |
| Cron | ✅ | drust timer দিয়ে |
| Web terminal | ✅ | Sandbox ছাড়া; Windows-এ ConPTY |
| SSL | 🔄 | Let's Encrypt-এর বদলে local CA |
| DNS | 🔄 | PowerDNS-এর বদলে hosts file |
| Mail | 🔄 | Postfix-এর বদলে local mail catcher |
| Redis | ⚠️ | macOS-এ সহজ; Windows-এ official build নেই, তাই queue ও cache database-এ চলবে |
| PostgreSQL, MongoDB | ⚠️ | ঐচ্ছিক, পরে |
| Docker | ⚠️ | Docker Desktop থাকলে; Windows socket path আলাদা, যাচাই দরকার |
| AI Gateway, MCP | ⚠️ | Speech ও OCR ছাড়া |
| FTP, Fail2ban, UFW, ClamAV, per-site user | ❌ | Local-এ অর্থহীন |
| Reseller, WHMCS, package plan | ❌ | লুকানো থাকবে |

## Platform-এর আলাদা কাজ

### macOS

- drust ইতিমধ্যে compile হয়, তাই এটাই platform layer পরীক্ষার সেরা জায়গা।
- php-fpm ও Unix socket একইভাবে কাজ করবে, তাই gateway-এর PHP অংশ প্রায় অপরিবর্তিত।
- প্রথম সংস্করণে PHP ও MariaDB Homebrew থেকে নিন। পরে signed binary bundle করুন।
- Apple Silicon ও Intel দুটোই build করুন।
- Port 80/443: সাধারণ user এই port-এ bind করতে পারবে কিনা আসল Mac-এ পরীক্ষা করতে হবে। না পারলে ছোট privileged helper বা `pf` redirect লাগবে।

### Windows

- **PHP-FPM ব্যবহার হবে না।** Windows-এ PHP সাধারণত `php-cgi.exe` দিয়ে FastCGI-তে চলে, আর official docs NTS build-এর কথা বলে।
- `connect_fpm` ([php.rs:306](../drust/src/edge_gateway/php.rs#L306)) এখন শুধু `UnixStream` নেয়। এতে TCP যোগ করুন। `send_fastcgi_request` আগে থেকেই generic, তাই পরিবর্তন ছোট।
- drust-কে কয়েকটা `php-cgi.exe` process-এর pool চালাতে হবে, আর নির্দিষ্ট সংখ্যক request-এর পর সেগুলো নতুন করে চালু করতে হবে। Pool-এর আচরণ prototype-এ আগে পরীক্ষা করুন।
- Laravel-এ `posix_*` call গুলো একটা wrapper-এ আনুন।
- `pcntl` না থাকায় queue worker-এর timeout কম নির্ভরযোগ্য হবে। Supervisor দিয়ে সামলান।
- Path: `PathService` ও drust ধরে নিয়েছে `/` separator। `C:\` path, space ও case-insensitive path-এর test লিখুন।
- Port 80 অন্য কিছু (IIS, http.sys, XAMPP) দখল করে থাকলে পরিষ্কার message দিন। `dpanel.ps1`-এ এই check আগে থেকেই আছে, সেটা reuse করুন।
- Long path, file lock (চলন্ত process-এর file মোছা যায় না) ও antivirus-এর কারণে ধীরগতি আগে থেকে ধরে রাখুন।

### Shell command প্রতিস্থাপন

| এখন | Desktop-এ |
| --- | --- |
| `tar`, `unzip`, `zipinfo` | `tar` ও `zip` crate (আগে থেকেই dependency) |
| `cp`, `timeout`, `kill` | `std::fs`, tokio |
| `id`, `getent`, `chown`, `useradd`, `runuser` | `AccountManager` |
| `openssl`, `certbot` | Rust-এ local CA, যেমন `rcgen` |
| `mysql`, `mysqldump` | সঙ্গে দেওয়া binary |
| `git`, `ssh`, `ssh-keygen` | User-এর system-এ থাকা বা সঙ্গে দেওয়া |
| `sh`, `bash` script | Rust-এ লেখা; `dscript` desktop-এ ব্যবহার হবে না |

## Phase

| Phase | কাজ | শেষের শর্ত | সময় |
| --- | --- | --- | --- |
| 0. ভিত্তি | CI, `whisper-rs` optional, `desktop` feature, spike | তিন OS-এ CI সবুজ | 2-3 সপ্তাহ |
| 1. Platform layer | Trait, path, capability flag | Linux test সব পাস, আচরণ অপরিবর্তিত | 3-4 সপ্তাহ |
| 2. macOS alpha | Supervisor, PHP, MariaDB, local CA | Mac-এ site বানিয়ে `https://mysite.test` খোলে | 4-6 সপ্তাহ |
| 3. Windows alpha | 60 error, php-cgi pool, path, service | Windows-এ একই flow কাজ করে | 6-8 সপ্তাহ |
| 4. Installer ও tray | Tauri tray, MSI/PKG, signing, auto-update | পরিষ্কার machine-এ এক click-এ install | 3-4 সপ্তাহ |
| 5. Beta | Migration, docs, WSL বন্ধের ঘোষণা | বাস্তব ব্যবহারকারীর feedback | 2-3 সপ্তাহ |

সময়গুলো অনুমান। Phase 0-এর spike-এর পর আবার হিসাব করুন।

### Phase 0-এর প্রথম কাজ

1. **GitHub Actions যোগ করুন।** এখন repo-তে `.github/workflows` নেই। Linux, `macos-latest` ও `windows-latest`-এ `cargo check`, পরে `cargo test`। এটাই এই plan-এর সবচেয়ে সস্তা ও দামি ধাপ, কারণ এতে আসল C build-এর উত্তরও পাওয়া যাবে।
2. `whisper-rs`-কে Cargo feature-এর পেছনে নিন। `ocr` ইতিমধ্যে optional, এটা নয়।
3. `desktop` Cargo feature বানান।
4. Spike: Windows-এ gateway + php-cgi দিয়ে একটা Laravel page চালান।
5. Spike: Mac-এ সাধারণ user হিসেবে port 80 পরীক্ষা করুন।

## Packaging

| | Windows | macOS |
| --- | --- | --- |
| Installer | MSI বা Inno Setup | Signed PKG বা DMG |
| Background | Windows Service | LaunchAgent/LaunchDaemon |
| Signing | Code signing certificate | Apple Developer ID ও notarization |
| Tray | Tauri | Tauri |

Signing ছাড়া Windows SmartScreen ও macOS Gatekeeper install আটকাবে বা ভয়ের সতর্কতা দেখাবে, তাই এতে টাকা লাগবে। Bundle করা PHP, MariaDB ইত্যাদির license ও notice-ও সঙ্গে দিতে হবে।

## ঝুঁকি

| ঝুঁকি | প্রভাব | সামলানোর উপায় |
| --- | --- | --- |
| Platform layer Linux server ভাঙে | খুব বেশি | ছোট ধাপ, প্রতি ধাপে test, Linux path অপরিবর্তিত |
| Windows-এ php-cgi ধীর বা অস্থির | বেশি | Phase 0 spike; খারাপ হলে Windows plan আবার ভাবতে হবে |
| Local panel থেকে privilege escalation | বেশি | কম অধিকার, শুধু localhost, token বাধ্যতামূলক |
| তিন platform maintain করার খরচ | বেশি | Capability flag, CI, desktop-এ feature সীমিত রাখা |
| Windows-এ কিছু PHP extension না পাওয়া | মাঝারি | সমর্থিত PHP extension-এর তালিকা প্রকাশ করুন |
| Code signing-এর খরচ | কম | Beta-র আগেই ব্যবস্থা করুন |

## যা এই plan-এ নেই

- Windows Server বা Mac-এ production hosting।
- Windows-এ IIS, per-site Windows user, Exchange বা অন্য mail server।
- Desktop থেকে সরাসরি public internet-এ site দেখানো। পরে tunnel feature ভাবা যেতে পারে।

## সিদ্ধান্ত দরকার

1. **লক্ষ্য কি শুধু local development?** এই plan তাই ধরে নিয়েছে। Windows-এ production hosting চাইলে plan পুরো বদলাবে, আর কাজ কয়েক গুণ বাড়বে।
2. **Mac-এ প্রথম সংস্করণে Homebrew চলবে, নাকি প্রথম থেকেই সব bundle?** প্রস্তাব: Homebrew দিয়ে শুরু।
3. **Apple Developer ও Windows code signing-এর খরচ দেবেন?** Public release-এর জন্য দরকার।
4. **WSL version কবে বন্ধ হবে?** প্রস্তাব: Native Windows beta স্থিতিশীল হওয়ার অন্তত এক release পরে।

## সূত্র

- [PHP on Windows: IIS/FastCGI](https://www.php.net/manual/en/install.windows.iis.php), [PHP Windows downloads](https://www.php.net/downloads.php)
- [Laravel Herd for Windows](https://herd.laravel.com/docs/windows/getting-started/installation)
- Compile result: `cargo check` (2026-11-07), repo-র একটা scratch copy-তে চালানো
