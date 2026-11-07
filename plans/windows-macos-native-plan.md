# dPanel Native Windows ও macOS Plan

WSL বা কোনো Linux VM ছাড়া dPanel সরাসরি Windows ও macOS-এ চালানোর plan।

তারিখ: 2026-11-07। Feature-এর plan আলাদা: [feature-gap-plan.md](feature-gap-plan.md)।

## সংক্ষেপে

দুটো লক্ষ্য, একই code base থেকে দুটো profile:

| Profile | কাজ | কীভাবে চলে |
| --- | --- | --- |
| **Local** | Development, XAMPP বা Laravel Herd-এর মতো | একজন user, শুধু localhost, mail ও DNS নকল |
| **Production** | Live deployment, public website | বহু site, আলাদা user, আসল SSL, mail, DNS ও firewall |

Local সহজ। Production অনেক বড় কাজ, কারণ dPanel-এর hosting অংশ Linux-এর ওপর দাঁড়ানো: প্রতি site-এ আলাদা Linux user, systemd, Postfix/Dovecot, PowerDNS, UFW, Fail2ban। এগুলোর প্রতিটার জন্য Windows ও macOS-এ বিকল্প লাগবে, যা [Production profile](#production-profile) অংশে আছে। macOS সবচেয়ে কঠিন, কারণ সেখানে CPU/RAM-এর hard limit নেই।

| বিষয় | সিদ্ধান্ত |
| --- | --- |
| ক্রম | macOS local → Windows local → Windows production → macOS production |
| Windows production | Windows Server 2022 ও 2025; Windows 10/11 শুধু local |
| macOS production | Windows-এর সমান পূর্ণ platform: বহু customer, reseller, WHMCS |
| মূল কৌশল | drust-এ platform layer, আর drust নিজেই সব service চালাবে |
| WSL সংস্করণ | Native local স্থিতিশীল না হওয়া পর্যন্ত থাকবে, তারপর বন্ধ |
| সময় (১ জন dev, অনুমান) | Local 5-6 মাস, Windows production আরও 8-10 মাস, macOS production আরও 5-6 মাস: মোট 18-22 মাস |

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

**4. Local profile-এ কম অধিকার।** Service যতটা সম্ভব সাধারণ user হিসেবে চলবে, SYSTEM বা root হিসেবে নয়। সব port শুধু `127.0.0.1`-এ bind হবে। Admin অধিকার লাগবে শুধু install, hosts file আর local CA trust-এর জন্য।

এটা জরুরি, কারণ Linux-এ drust root হিসেবে চলে। সেই নকশা PC-তে হুবহু চালালে একটা local privilege escalation-এর পথ তৈরি হবে।

Production profile-এ drust-কে SYSTEM বা root হিসেবে চলতেই হবে, কারণ user ও firewall বানাতে হয়। সেখানে নিয়মটা Linux-এর মতোই: API শুধু localhost-এ ও token দিয়ে, আর panel ও website কম অধিকারের account-এ।

## Local profile: কী থাকবে

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

## Production profile

Windows ও macOS দুটোই পূর্ণ production platform, Linux-এর সমান মর্যাদায়। একই gateway, panel ও platform layer থাকবে, শুধু নিচের backend বদলাবে। IIS লাগবে না: Rust edge gateway-ই public 80/443 সামলাবে।

```text
                         Browser / Mail client / DNS client
                                        │
  ┌─────────────────────────────────────┴──────────────────────────────────┐
  │ Public edge      Rust edge gateway · mail ports · DNS · FTP/SFTP       │
  ├────────────────────────────────────────────────────────────────────────┤
  │ Control plane    dPanel (Laravel + Vue)  ◄── token ──►  drust API      │
  │                  একই code: UI, auth, billing, reseller, queue           │
  ├────────────────────────────────────────────────────────────────────────┤
  │ Platform layer   Service · Account · IsolatedRunner · ResourceLimiter  │
  │ (একই interface)   Firewall · Dns · Mail · Acme · Paths · Capabilities   │
  ├───────────────────────┬────────────────────────┬───────────────────────┤
  │ Linux                 │ Windows Server         │ macOS                 │
  │ systemd, cgroup       │ Service, Job Object    │ launchd, supervisor   │
  │ ext4/XFS quota        │ NTFS quota             │ APFS volume quota     │
  │ UFW, PowerDNS         │ Defender FW, DNS role  │ pf, external DNS      │
  │ Postfix, Dovecot      │ Stalwart               │ Stalwart              │
  └───────────────────────┴────────────────────────┴───────────────────────┘
```

**নিয়ম:** Platform layer-এর ওপরের কোনো code OS-এর নাম জানবে না। নতুন feature মানে আগে interface, তারপর তিন OS-এর implementation। কোনো OS-এ কিছু না থাকলে সেটা capability flag দিয়ে জানানো হবে, `if windows` দিয়ে নয়।

| কাজ | Linux (এখন) | Windows Server | macOS |
| --- | --- | --- | --- |
| Service | systemd | Windows Service | LaunchDaemon |
| PHP | প্রতি site-এ FPM pool | প্রতি site-এ php-cgi pool | প্রতি site-এ FPM pool |
| Site isolation | Linux user, ACL | Local Windows user, NTFS ACL | `dscl` user, POSIX permission |
| CPU/RAM limit | cgroup (feature plan 1.4) | Job Object (hard limit) | drust supervisor (soft limit); hard limit চাইলে tenant VM |
| Disk quota | Filesystem quota (feature plan 1.2) | NTFS per-user quota (যাচাই দরকার) | প্রতি account-এ APFS volume ও quota |
| SSL | certbot | Rust ACME client | Rust ACME client |
| DNS | PowerDNS | Windows DNS Server role | External DNS (Cloudflare API) |
| Mail | Postfix, Dovecot, Rspamd | Stalwart | Stalwart |
| Webmail | Roundcube | Roundcube | Roundcube |
| FTP/SFTP | বিদ্যমান | libunftp, Windows OpenSSH | libunftp, macOS SSH |
| Firewall | UFW | Windows Defender Firewall | `pf` |
| Brute-force রক্ষা | Fail2ban | drust watcher + firewall | drust watcher + firewall |
| Malware scan | ClamAV | ClamAV | ClamAV |
| Web terminal | bwrap sandbox | প্রথম সংস্করণে বন্ধ | প্রথম সংস্করণে বন্ধ |
| Docker | বিদ্যমান | প্রথম সংস্করণে বন্ধ | প্রথম সংস্করণে বন্ধ |
| Reseller, WHMCS, package | বিদ্যমান | চালু | চালু |

### বড় সিদ্ধান্তগুলোর কারণ

**Mail: Stalwart।** Postfix ও Dovecot Windows-এ চলে না। Stalwart-এর সর্বশেষ release (v1.0.3, 2026-11-04)-এ Windows ও macOS দুটোরই build আছে, এবং spam filter ভেতরেই আছে। jabali-panel-ও Stalwart ব্যবহার করে।

- License AGPL-3.0 ও commercial, দুই ধরনের। dPanel free হলেও AGPL-এর শর্ত প্রযোজ্য, কারণ dPanel-এর license open-source নয়। তাই আলাদা process হিসেবে অপরিবর্তিত চালান, আর bundle করার আগে শর্ত দেখে নিন।
- Laravel-এর mail অংশ Dovecot-এর সঙ্গে বাঁধা (`/etc/dovecot`-এর 11টা reference)। তাই আগে একটা `MailBackend` interface লাগবে, যার দুটো implementation থাকবে।
- Windows-এ mail সবচেয়ে বড় অজানা অংশ। আগে একটা spike করুন।

**DNS: Windows DNS role।** Hickory DNS Rust-এ হওয়ায় লোভনীয়, কিন্তু তার নিজের docs এখনো production-এ চালাতে নিষেধ করে। PowerDNS-এর আধুনিক Windows build-এর কোনো প্রমাণ পাওয়া যায়নি। Windows-এ তাই `DnsServer` PowerShell module। macOS-এ external DNS, কারণ local DNS server সেখানে দরকার নেই।

**SSL: Rust ACME।** `certbot` বাদ দিয়ে drust-এর ভেতরে ACME client (`instant-acme` একটা বিকল্প)। এটা Linux-এও লাভজনক, আর DNS-01 দিয়ে feature plan-এর wildcard SSL-ও পাওয়া যাবে। `instant-acme`-এর README শুধু DNS challenge পরীক্ষার কথা বলে, তাই HTTP-01 নিজে পরীক্ষা করুন।

**Isolation: প্রতি site-এ আলাদা Windows user।** drust প্রতিটা site-এর `php-cgi.exe` সেই user হিসেবে চালাবে, একটা Job Object-এর ভেতরে। এতে একই সঙ্গে isolation ও CPU/RAM limit পাওয়া যাবে। Job Object-এ memory ও CPU-র hard limit আছে (Microsoft docs)। এটাই Windows production-এর সবচেয়ে সংবেদনশীল code, তাই আলাদা security review লাগবে।

### macOS-এ পূর্ণ production কীভাবে

macOS-এ Linux-এর cgroup বা Windows-এর Job Object-এর মতো জিনিস নেই, আর Apple 2022-এ macOS Server বন্ধ করে দিয়েছে। তাই এই অংশটাই সবচেয়ে কঠিন, আর বাকি অংশের চেয়ে বেশি নিজে বানাতে হবে।

**Disk: প্রতি account-এ APFS volume।** `diskutil apfs addVolume ... -quota` দিয়ে hard limit পাওয়া যায়। তবে তিনটা অজানা আছে, তাই Phase 6-এ পরীক্ষা করুন:

- তৈরির পর quota বদলানোর নথিভুক্ত উপায় পাওয়া যায়নি, তাই plan বদলাতে volume নতুন করে বানাতে হতে পারে।
- একটা report বলছে macOS 13-এ একটা Time Machine volume-এর quota নিজে থেকে উঠে গিয়েছিল।
- একটা machine-এ কয়েকশো volume কেমন চলে, সেটাও মাপতে হবে।

**CPU/RAM: drust supervisor।** `setrlimit` macOS-এ নির্ভরযোগ্য নয়: `RLIMIT_AS` ও `RLIMIT_DATA` enforce হয় না বলে একাধিক report আছে (কিছু পুরোনো)। তাই drust প্রতি account-এর CPU ও memory নিয়মিত মেপে আগে process-এর priority কমাবে, তারপর limit ছাড়ালে process restart করবে।

এটা **soft limit**: হঠাৎ বেড়ে যাওয়া load কয়েক সেকেন্ড অন্যদের ওপর প্রভাব ফেলতে পারে। Package-এ এটা পরিষ্কার লিখতে হবে।

**Hard limit চাইলে: tenant VM।** `ResourceLimiter`-এর দ্বিতীয় implementation হিসেবে customer-এর site একটা হালকা VM-এ চলবে। এতে hard limit পাওয়া যায়, কিন্তু প্রতি site-এ বেশি RAM লাগে, আর site-এর ভেতরের অংশ তখন আর native থাকে না। এটা পরে, দরকার হলে।

**License: dPanel-এর জন্য বাধা নয়।** dPanel সবার জন্য free, তাই dPanel বিতরণ করায় macOS-এর leasing শর্তের প্রশ্ন আসে না। Apple-এর license-এ Mac lease দেওয়ার শর্ত আছে (Big Sur-এর সময়ের terms; বর্তমান text যাচাই করা হয়নি), কিন্তু সেটা Mac ভাড়া দেওয়ার ব্যাপার।

তবে dPanel-এর license ব্যবহারকারীদের hosting বিক্রির অনুমতি দেয়। তাই কেউ Mac-এ বাণিজ্যিক hosting চালাতে চাইলে তার নিজের Apple license দেখে নেওয়া উচিত, এই এক লাইনের সতর্কতা docs-এ দিন।

**Hardware:** Apple hardware ছাড়া চলবে না। Data center-এ Mac mini/Studio, আর OS update-এর reboot সামলানোর ব্যবস্থা লাগবে।

### নতুন interface

Platform layer-এ এগুলোও লাগবে: `IsolatedRunner`, `ResourceLimiter`, `FirewallBackend`, `DnsBackend`, `MailBackend`, `AcmeClient`।

[Feature plan](feature-gap-plan.md)-এর Phase 1 (suspend, quota, limit) এই interface-এর ওপর বানান। Linux-only করে বানালে পরে আবার লিখতে হবে। তাই Phase 1 শুরুর আগে অন্তত interface-এর নকশা ঠিক করে নিন।

## Phase

### Local

| Phase | কাজ | শেষের শর্ত | সময় |
| --- | --- | --- | --- |
| 0. ভিত্তি | CI, `whisper-rs` optional, `desktop` feature, spike | তিন OS-এ CI সবুজ | 2-3 সপ্তাহ |
| 1. Platform layer | Trait, path, capability flag | Linux test সব পাস, আচরণ অপরিবর্তিত | 3-4 সপ্তাহ |
| 2. macOS alpha | Supervisor, PHP, MariaDB, local CA | Mac-এ site বানিয়ে `https://mysite.test` খোলে | 4-6 সপ্তাহ |
| 3. Windows alpha | 60 error, php-cgi pool, path, service | Windows-এ একই flow কাজ করে | 6-8 সপ্তাহ |
| 4. Installer ও tray | Tauri tray, MSI/PKG, signing, auto-update | পরিষ্কার machine-এ এক click-এ install | 3-4 সপ্তাহ |
| 5. Beta | Migration, docs, WSL বন্ধের ঘোষণা | বাস্তব ব্যবহারকারীর feedback | 2-3 সপ্তাহ |

### Production

| Phase | কাজ | শেষের শর্ত | সময় |
| --- | --- | --- | --- |
| 6. Production spike | php-cgi load test, Stalwart, Windows isolation, APFS quota, macOS limit | Go/no-go সিদ্ধান্ত | 4-5 সপ্তাহ |
| 7. Backend interface | `MailBackend`, `DnsBackend`, `FirewallBackend`, Rust ACME | Linux-এ নতুন interface দিয়ে সব test পাস | 6-8 সপ্তাহ |
| 8. Windows isolation | Per-site user, ACL, Job Object, quota | এক site অন্য site-এর file পড়তে পারে না, test দিয়ে প্রমাণিত | 6-8 সপ্তাহ |
| 9. Windows services | SSL, DNS, Stalwart, Roundcube, FTP, firewall, brute-force রক্ষা | আসল domain-এ site ও mail চলে | 8-10 সপ্তাহ |
| 10. Windows hardening | Security review, penetration test, update, backup restore | Review-এর গুরুতর সমস্যা শূন্য | 4-6 সপ্তাহ |
| 11. macOS isolation ও limit | `dscl` user, APFS volume, supervisor limit | এক site অন্যের file পড়তে পারে না; limit test পাস | 8-10 সপ্তাহ |
| 12. macOS services | LaunchDaemon, `pf`, Stalwart, external DNS, reseller, WHMCS | আসল domain-এ site ও mail চলে | 6-8 সপ্তাহ |
| 13. macOS hardening | Security review, penetration test, reboot ও update flow | Review-এর গুরুতর সমস্যা শূন্য | 4-6 সপ্তাহ |

Phase 6 সবচেয়ে গুরুত্বপূর্ণ। এই চারটা উত্তর না পেলে Phase 7-এ যাবেন না:

1. Windows-এ php-cgi বাস্তব load-এ Linux-এর PHP-FPM-এর তুলনায় কত ধীর?
2. একটা Windows server-এ কতগুলো আলাদা-user site চালানো যায়?
3. Stalwart-এ mailbox ও DKIM তৈরি কি dPanel-এর বর্তমান mail flow-এর সব কাজ করতে পারে?
4. macOS-এ APFS quota ও supervisor limit বাস্তবে টেকে কি?

সময়গুলো অনুমান। Phase 0 ও Phase 6-এর spike-এর পর আবার হিসাব করুন।

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

Signing ছাড়া Windows SmartScreen ও macOS Gatekeeper install আটকাবে বা ভয়ের সতর্কতা দেখাবে। dPanel free হলেও এই খরচটা project-কে দিতে হবে। Bundle করা PHP, MariaDB ইত্যাদির license ও notice-ও সঙ্গে দিতে হবে।

## ঝুঁকি

| ঝুঁকি | প্রভাব | সামলানোর উপায় |
| --- | --- | --- |
| Platform layer Linux server ভাঙে | খুব বেশি | ছোট ধাপ, প্রতি ধাপে test, Linux path অপরিবর্তিত |
| Windows-এ php-cgi ধীর বা অস্থির | বেশি | Phase 0 spike; খারাপ হলে Windows plan আবার ভাবতে হবে |
| Local panel থেকে privilege escalation | বেশি | কম অধিকার, শুধু localhost, token বাধ্যতামূলক |
| Windows site isolation-এ ফাঁক: এক customer অন্যের data পড়ে | খুব বেশি | Automated isolation test, আলাদা security review, Phase 10 পাস না করলে release নয় |
| Windows-এ production mail-এর deliverability ও maintenance | বেশি | Phase 6 spike; দরকার হলে প্রথম সংস্করণ mail ছাড়া ছাড়ুন |
| দুই mail stack (Postfix ও Stalwart) maintain করা | বেশি | `MailBackend` interface, দুই stack-এই একই test |
| macOS-এ soft limit-এর কারণে এক customer অন্যদের ধীর করে | বেশি | Supervisor test, কম density, দরকারে tenant VM |
| dPanel free, কিন্তু Windows Server-এর license ব্যবহারকারীকে কিনতে হয় | মাঝারি | Docs-এ পরিষ্কার লিখুন; Linux free বিকল্প হিসেবে থাকবে |
| Free project-এ signing ও test hardware-এর খরচ আয় ছাড়াই আসে | মাঝারি | Donation বা sponsor; খরচ কম রাখতে GitHub-hosted runner |
| তিন platform maintain করার খরচ | বেশি | Capability flag, CI, desktop-এ feature সীমিত রাখা |
| Windows-এ কিছু PHP extension না পাওয়া | মাঝারি | সমর্থিত PHP extension-এর তালিকা প্রকাশ করুন |
| Code signing-এর খরচ | কম | Beta-র আগেই ব্যবস্থা করুন |

## যা এই plan-এ নেই

- Windows 10/11-এ production। ওগুলো শুধু local profile-এর জন্য।
- IIS বা ASP.NET hosting। Windows-এও Rust gateway-ই web server।
- Windows-এ Exchange বা Active Directory integration।
- Local profile থেকে সরাসরি public internet-এ site দেখানো। পরে tunnel feature ভাবা যেতে পারে।

## সিদ্ধান্ত দরকার

**নেওয়া হয়েছে:** Local development ও live production দুটোই লক্ষ্য, আর Windows ও macOS দুটোই পূর্ণ production platform।

1. **Production-এর প্রথম সংস্করণে mail লাগবে?** প্রস্তাব: Phase 6-এর ফল দেখে ঠিক করুন।
2. **Mac-এ local প্রথম সংস্করণে Homebrew চলবে, নাকি প্রথম থেকেই সব bundle?** প্রস্তাব: Homebrew দিয়ে শুরু।
3. **Apple Developer ও Windows code signing-এর খরচ কোথা থেকে আসবে?** dPanel free হলেও এটা লাগবে, নইলে install-এ সতর্কতা আসবে। Apple-এর জন্য বছরে একটা fee দিতে হয়।
4. **WSL version কবে বন্ধ হবে?** প্রস্তাব: Native Windows local স্থিতিশীল হওয়ার অন্তত এক release পরে।

## সূত্র

- [PHP on Windows: IIS/FastCGI](https://www.php.net/manual/en/install.windows.iis.php), [PHP Windows downloads](https://www.php.net/downloads.php)
- [Laravel Herd for Windows](https://herd.laravel.com/docs/windows/getting-started/installation)
- [Stalwart releases](https://github.com/stalwartlabs/stalwart/releases), [Stalwart README](https://github.com/stalwartlabs/mail-server)
- [Hickory DNS server](https://docs.rs/crate/hickory-server/latest), [Windows DnsServer module](https://learn.microsoft.com/powershell/module/dnsserver)
- [Job Object limits](https://learn.microsoft.com/en-us/windows/win32/api/winnt/ns-winnt-jobobject_extended_limit_information), [Job Object CPU rate](https://learn.microsoft.com/en-us/windows/win32/api/winnt/ns-winnt-jobobject_cpu_rate_control_information)
- [APFS quota: `addVolume`](https://eclecticlight.co/2017/04/08/how-to-make-your-own-apfs-volume/), [quota বদলানোর সীমা](https://openradar.appspot.com/34096467), [macOS 13 quota report](https://mjtsai.com/blog/2021/01/19/how-to-reserve-time-machine-space-on-an-apfs-drive/)
- [macOS rlimit report](https://ai-fast-downward.dmi.unibas.ch/issue825), [Darwin limit code](https://docs.rs/crate/vetto/latest/source/src/sandbox/macos/limits.rs)
- [macOS leasing terms](https://9to5mac.com/2020/11/11/macos-big-sur-adds-leasing-terms-that-pull-hosting-services-like-macstadium-out-of-a-legal-gray-area/), [macOS Server বন্ধ](https://www.macworld.com/article/634243/apple-discontinues-macos-server-after-23-years.html)
- [instant-acme](https://docs.rs/crate/instant-acme/latest/source/README.md), [libunftp](https://docs.rs/crate/libunftp/latest), [ClamAV on Windows](https://docs.clamav.net/faq/faq-win32.html)
- Compile result: `cargo check` (2026-11-07), repo-র একটা scratch copy-তে চালানো
