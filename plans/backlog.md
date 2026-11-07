# Implementation Backlog

পুরো কাজের task তালিকা। প্রতিটা task ছোট, আলাদা commit-যোগ্য, আর তার শেষের শর্ত আছে। Technical contract: [platform-spec.md](platform-spec.md)।

**Status:** A1-A6, B1-B8, D1, D2 শেষ; `5448f8b`-এ CI-র চারটা job সবুজ (Rust Linux 300, Windows 304, macOS 301 passed; Laravel Linux 647 passed, 13 skipped)। C1, C2 ও C4-এর code শেষ, তবে commit push হয়নি, তাই CI-তে যাচাই বাকি। F1-এর unit test পাস, F2 চলছে, H1-এর সিদ্ধান্ত হয়নি (নিচে দেখুন)। কাজের সময় `Depends`-এর ক্রম মেনে চলুন।

**খোলা বিষয়:**

- `skip_until_windows_has` আর code-এ নেই; কোনো test সেটা দিয়ে skip হয় না।
- C1: code-এর কাজ শেষ, CI বাকি। `platform/`-এর বাইরে test ছাড়া যা থাকল: `/usr/bin/env` ও `PATH=` string (C5), `ALLOWED_ROOT_PREFIXES` (নিচে), আর `migration_ssh.rs`-এর remote server-এর path। Comment ও error message-এ `/etc/drust` লেখা আছে, সেগুলো code নয়। ClamAV এখন শুধু Linux-এ: `clamav` capability বন্ধ থাকলে scan চুপচাপ বাদ যায়, UI-তে কিছু দেখায় না। J10 ও K7-এ `timeout`-এর অন্য পথ বানিয়ে capability খুলতে হবে।
- C4: code-এর কাজ শেষ, CI বাকি। `loginctl`, `pkill` এখন `AccountManager::end_sessions`-এ, আর `create_user.rs`-এর `chown`, `chmod`, `install` এখন `FileAccess` দিয়ে। Linux-এ আসল user-এর ওপর `end_sessions` চালিয়ে দেখা হয়নি।
- C2: code-এর কাজ শেষ, CI বাকি। `platform/service.rs`-এ `ServiceManager`; C2-এর সব file-এ `systemctl` command আর নেই। থাকল: `api/terminal.rs`-এর blocklist (user-এর command আটকায়), দুটো comment। যেখানে `services` বন্ধ, সেখানে PostgreSQL, MongoDB ও Local LLM-এর route `404`, আর menu ও database page থেকে সেগুলো লুকানো; supervisor এলে capability খুলবে।
- C3 শেষ নয়। `node_process.rs`, `python_process.rs`, `rust_process.rs`, `php.rs`-এর `systemctl` ও `journalctl` এখন `ServiceManager`-এ। বাকি: এই file-গুলোতে ও `laravel_queue.rs`-এ systemd unit file লেখা ও `/etc/systemd` path, আর `Done`-এর শর্ত, কারণ supervisor (E2, F4) এখনও নেই।
- `chown` ও `chmod`-এর অন্য call এখনও `platform/`-এর বাইরে: `filemanager/` (`common`, `unzip`, `fix_permissions`), `api/` (`ssh_key`, `git_deploy`, `mailbox_storage`), `edge_gateway/` (`dispatcher`, `terminal_ws`), `installer/service.rs`, আর `try_chown`-এর caller। C9 শুধু কয়েকটা ঢাকে; বাকিগুলোর কোনো task নেই, C11-এর আগে একটা দরকার।
- F1: Windows `Paths`, `FileAccess` ও local `AccountManager`-এর unit test পাস। `filemanager/e2e_local.rs` ignored, হাতে চালাতে হয়; suite সেটা প্রমাণ করে না। F1-এর `Depends`-এর C1 ও C4 CI-র অপেক্ষায়।
- F2 চলছে, আর **H1 পাস করার আগেই** শুরু হয়েছে, আর H1-এর সিদ্ধান্ত এখনও হয়নি ([spikes/H1.md](spikes/H1.md))। H1 pool-এর নকশা বদলাতে পারে। হয়েছে: `edge_gateway/php_cgi_pool.rs`, spec §9-এর default, প্রতি site-এ pool, TCP-তে `FastCgiEndpoint`। Crash, recycle, idle, queue ও 503-এর test একটা stand-in process দিয়ে পাস। আসল php-cgi দিয়ে শুধু একবার হাতে দেখা: `mysite.test` render হয়, worker মেরে দিলে পরের request-এ নতুন worker আসে। আসল php-cgi-র 500 request recycle চালানো হয়নি।
- F2-এ বাকি: gateway হঠাৎ মরলে php-cgi থেকে যায় (Job Object দরকার); "alert" এখন শুধু log; এই PC-র php-cgi TS build, spec চায় NTS; `DRUST_API_TOKEN` PHP-তে না যাওয়া শুধু unit test-এ প্রমাণিত, আসল process-এ নয়।
- Windows local-এ gateway চলে: `DPANEL_PROFILE=local`, `DPANEL_PANEL_ROOT`, `DRUST_HTTP_BIND`, `DRUST_HTTPS_BIND`, `DRUST_API_TOKEN` লাগে, আর `PATH`-এ PHP। SQLite panel-এর site PHP CLI দিয়ে পড়ে, কারণ Rust-এ SQLite crate নেই; প্রতি reload-এ কয়েকটা PHP process চলে (মাপা reload 0.7-0.9 সেকেন্ড)। Port 80-তে এখনও `php artisan serve`।
- বাকি system path অন্য task-এর: `/etc/systemd` C2/C3, `/etc/passwd` ও `/etc/shadow` C4, sandbox-এর ভেতরের path ও `runuser` C5, `/etc/cron.d` C10, `/etc/letsencrypt` I5, `/etc/fail2ban` J7, ClamAV J10, `/etc/php` ও PHP-FPM pool F2/J4।
- `/home` ইচ্ছা করে থেকে গেছে: `migration_ssh.rs` (remote server-এর path), sandbox-এর ভেতরের path ও terminal blocklist (C5), `ALLOWED_ROOT_PREFIXES` (Windows ও macOS-এ scan root-এর নকশা দরকার; এখন সেখানে scan চলবে না)।
- Git for Windows-এ `core.autocrlf` চালু থাকে। Deploy-এর clone-এ সেটা site file-এ CRLF আনবে; F8-এর আগে সিদ্ধান্ত দরকার।

## সব task-এর শেষের শর্ত

একটা task তখনই শেষ, যখন:

1. Task-এর `Done` শর্ত পূরণ হয়েছে, আর সেটা test দিয়ে প্রমাণিত।
2. Linux-এর বিদ্যমান test পাস: `cargo test` (এখন 251টা Rust test, 4টা ignored) ও `php artisan test` (এখন 566টা test, 1টা skipped)।
3. CI তিন OS-এ সবুজ (A1-এর পর)।
4. নতুন Vue অংশ আলাদা component-এ। `Manage.vue`-এর মতো বড় page আর বাড়ে না।
5. `#[cfg]` শুধু `drust/src/platform/`-এ।
6. Spec বদলালে `platform-spec.md` একই commit-এ বদলানো হয়েছে।
7. `main`-এ commit হয়েছে।

## ধরে নেওয়া সিদ্ধান্ত

কাজ যেন আটকে না থাকে, তাই এগুলো default। বদলাতে চাইলে সংশ্লিষ্ট task শুরুর আগে বদলান।

| বিষয় | Default | কোন task-এ লাগে |
| --- | --- | --- |
| Bandwidth limit পেরোলে | `notify` | P3 |
| `terminate` করলে data কতদিন | 30 দিন, তারপর মোছা | P1 |
| Production-এ প্রথম সংস্করণে mail | H3 ভালো হলে আছে; না হলে পরের release | J5, K4 |
| Mac local-এ PHP ও MariaDB | Homebrew দিয়ে শুরু | E2 |
| Code signing | Public release-এর আগে; alpha unsigned | G4 |

## প্রথম দশটা কাজ

A1 → A2 → A3 → B1 → B2 → B3 → B4 → B5 → B6 → B7

এর পর B8 শেষ হলে H1 শুরু করা যায়।

## কাজের ধারা

```text
A ভিত্তি ─► B Windows compile ─► C Platform migration ─► D Laravel portability
                                        │
                                        ├─► E macOS local ─► F Windows local ─► G Packaging
                                        │
                  H Spike ──────────────┴─► I Backend ─► J Windows production ─► K macOS production

P, S, M, W, U (feature gap): I-এর interface ঠিক হলে সমান্তরালে চলতে পারে
```

## A. ভিত্তি

| ID | কাজ | Depends | Files | Done |
| --- | --- | --- | --- | --- |
| A1 | GitHub Actions: Linux, macOS, Windows-এ `cargo check` ও `cargo test`; Linux-এ `php artisan test` | - | `.github/workflows/ci.yml` | Linux সবুজ; Windows ও macOS job চলে, ব্যর্থ হলেও report দেয় |
| A2 | `whisper-rs`-কে `speech` feature-এর পেছনে নেওয়া | - | `drust/Cargo.toml`, `drust/src/api/media.rs`, `drust/deploy/install-service.sh` | Linux আগের মতো build; feature বন্ধেও compile; transcribe পরিষ্কার error দেয় |
| A3 | `drust/src/platform/` skeleton, `PlatformError`, `current()` | - | Spec §3, §4 | Linux implementation বিদ্যমান আচরণ wrap করে |
| A4 | `Capability` ও `GET /capabilities` | A3 | Spec §5; `drust/src/api.rs` | তিন OS-এর জন্য unit test |
| A5 | Laravel `PlatformCapabilities`, `RequireCapability`, Inertia shared prop | A4 | Spec §3, §5; `dpanel/app/Support/Platform/`, `dpanel/bootstrap/app.php` | বন্ধ capability-র route `404`; sidebar-এ দেখায় না |
| A6 | `DPANEL_PROFILE` config | A3 | `drust/deploy/*.env.example`, `dpanel/.env.example`, `dpanel/config/app.php` | Default `server`; Linux অপরিবর্তিত |

## B. Windows compile (60 error)

লক্ষ্য: `x86_64-pc-windows-msvc`-এ `cargo check` পাস। প্রতিটা task-এ Linux-এ আচরণ অপরিবর্তিত থাকতে হবে।

| ID | কাজ | Depends | Files (error line) | Done |
| --- | --- | --- | --- | --- |
| B1 | `FileAccess`-এর Linux implementation | A3 | Spec §6.2 | Unit test: সব mode ঠিক |
| B2 | Secret ও config file | B1 | `sso_ticket.rs` (9, 17, 25), `pgadmin_sso.rs` (21, 142, 143, 247), `api/database_config.rs` (3, 113, 124), `api/mailbox_storage.rs` (1, 108), `ssl/certificate.rs` (191, 192) | Unix import নেই; mode আগের মতো |
| B3 | File manager | B1 | `filemanager/upload.rs` (3, 68, 149, 173, 189), `filemanager/unzip.rs` (4, 175, 301, 357), `filemanager/write.rs` (2, 34), `filemanager/browse.rs` (2, 139) | Upload ও unzip test পাস |
| B4 | Sync ও migration | B1 | `api/file_sync.rs` (5, 103, 104, 293, 482), `api/migration_ssh.rs` (13, 548, 802) | Owner ও mode আগের মতো |
| B5 | Gateway | B1 | `edge_gateway/dispatcher.rs` (12, 727, 733), `edge_gateway/rust_process.rs` (81, 84), `edge_gateway/terminal_ws.rs` (4, 246, 261, 291) | Gateway test পাস |
| B6 | `FastCgiEndpoint` | A3 | `edge_gateway/php.rs` (18, 306); Spec §6.8 | Unix ও TCP দুটোরই FastCGI test |
| B7 | বাকি | B1 | `cron.rs` (2, 52), `docker/stack.rs` (10, 296, 305), `security_scan.rs` (14, 525, 976), `php/cli.rs` (31, 42), `installer/service.rs` (10, 295, 310, 354) | Windows `cargo check` পাস |
| B8 | আসল Windows ও macOS-এ C dependency build | B7 | `ring`, `aws-lc-sys`, CI | CI-তে MSVC ও macOS build সবুজ; A1-এর job বাধ্যতামূলক করা |

B7-এর পর MSVC-তে `cargo check`-এ 0 error (শুরুতে 58), test code সহ। `platform/`-এর বাইরে `cfg(unix)` বা `std::os::unix` নেই। F1-এর আগে Windows-এ FileAccess-এর সব call `Unsupported` দেয়, আর security scan-এর permission check কিছু report করে না।

B7-এর পর নতুন error বেরোলে একই নিয়মে ঠিক করুন। Compile result-এর শর্ত: [native plan](windows-macos-native-plan.md#মাপা-হয়েছে-drust-compile-হয়-কিনা)।

## C. Platform migration

`Command::new` ও hardcoded path সরানো। ক্রম: কম ঝুঁকি থেকে বেশি।

| ID | কাজ | Depends | Files | Done |
| --- | --- | --- | --- | --- |
| C1 | `Paths` | A3 | Spec §6.1; `/var/www`, `/etc/drust`, `/usr/bin`, `/run/php`-এর প্রায় 257টা reference | `platform/`-এর বাইরে এসব path নেই (test ছাড়া); `skip_until_windows_has("C1")` সরানো |
| C2 | `ServiceManager` ও supervisor | A3 | Spec §6.3; `service_toggle.rs`, `postgresql.rs`, `security.rs`, `upload_scan.rs`, `php_config.rs`, `laravel_queue.rs`, `api/laravel_queue.rs`, `api/terminal.rs`, `api/website.rs`, `ssl/certificate.rs`, `app/admin/disable_root.rs` | `platform/`-এর বাইরে `systemctl` নেই |
| C3 | App process-এর systemd unit | C2 | `edge_gateway/python_process.rs`, `node_process.rs`, `rust_process.rs`, `php.rs` | Node, Python, Rust app সব OS-এ supervisor দিয়ে চলে |
| C4 | `AccountManager` | B1 | Spec §6.4; `app/admin/create_user.rs`, `filemanager/user.rs`, `login_users.rs`, `api/website.rs` | `platform/`-এর বাইরে `useradd` ইত্যাদি নেই |
| C5 | `IsolatedRunner` | C4 | Spec §6.5; `app/support/process.rs` আগে, তারপর `runuser` ব্যবহারকারী file | Environment allowlist test |
| C6 | Archive | C1 | `api/migration_archive.rs` (17), `api/migration.rs` (15), `api/backup.rs`: `tar`, `unzip`, `zipinfo`, `cp`-এর বদলে crate | Backup ও migration round-trip test; Linux archive-এর format অপরিবর্তিত |
| C7 | Database tools | C1 | `database/request.rs`, `database/postgresql.rs`, `api/backup.rs` | `Paths::binary()` থেকে binary |
| C8 | `api/website.rs` ভাগ করা (29টা `Command::new`) | C2, C4, C5 | `api/website.rs` | ছোট module; কোনো OS command সরাসরি নেই |
| C9 | বাকি | C5 | `api/ftp.rs`, `api/git_deploy.rs`, `api/ssh_key.rs`, `fail2ban.rs`, `docker.rs` | এই file-গুলোতে OS command সরাসরি নেই; সব platform layer দিয়ে যায় |
| C10 | Scheduler | C5 | Spec §6.13; `cron.rs` | Windows ও macOS-এ timer; Linux-এ crontab |
| C11 | CI guard | C1-C9 | CI script | `platform/`-এর বাইরে `cfg` বা Linux path এলে CI fail |

## D. Laravel portability

| ID | কাজ | Depends | Files | Done |
| --- | --- | --- | --- | --- |
| D1 | `ProcessIdentity` দিয়ে `posix_*` সরানো | A5 | 8টা file: `RemoteMysqlAccessService`, `WordpressInstallService`, `LocalPortScanner`, `SelfConnectionController`, `SecurityController`, `WebsiteController`, `MigrateDovecotSqlMailboxesCommand`, `SelfConnectionOrchestrator` | `ext-posix` ছাড়া test পাস |
| D2 | Path-কে OS-নিরপেক্ষ করা | C1 | `PathService.php`, `ScriptPathResolver.php`; `C:\`, space ও case test | Windows path test পাস |
| D3 | Monitoring ও dashboard drust থেকে নেওয়া | C2 | `DashboardController`, `MonitoringController`, `SystemdStackServiceManager` | PHP থেকে `systemctl`, `/proc` পড়া নেই |
| D4 | `LocalCommand` | D1 | OS-নির্ভর বাকি file (মোট 43টা, D1-এর 8টা সহ); `Services/Mcp/*`, `DatabaseDumper`, `FtpServerInfo`, `LocalPortScanner` | প্রতিটা file হয় portable, নয়তো capability-র পেছনে |
| D5 | MCP policy OS-সচেতন করা | D4 | `McpCommandPolicy`, `RunCommandTool`, `RestartServiceTool`, `ServiceStatusTool` | Windows command policy test |
| D6 | Local-এ queue ও cache database-এ | A6 | `dpanel/.env.example`, `config/queue.php`, `config/cache.php` | Redis ছাড়া panel চলে |

**অবস্থা (Windows, `ext-posix` ছাড়া):**

- D2: Done শর্ত test-এ প্রমাণিত (`PathServiceTest`, `ScriptPathResolverTest`, `SiteHomeRoutingTest`)। Windows local-এ `SERVER_BASE_DIR`-এ drust-এর sites root দিতে হয়।
- D1: Done। `posix_*` শুধু `ProcessIdentity`-তে। Windows-এ `ext-posix` ছাড়া `php artisan test`: 659 passed, 0 failed, 1 skipped (Git Bash ও PowerShell দুটোতেই)। Linux CI-তেও পাস (`5448f8b`)।
- D4 (আংশিক): `Services/Mcp/*`-এর file tool, `tar` ও `DatabaseController`-এর Windows skip ঠিক হয়েছে। MCP command এখন `McpCommandExecutor::quote` দিয়ে bash-এর জন্য quote হয়; Windows path filter `PathService::isPlainDrivePath`-এ (Spec §11 item 7)। আসল `mcp-exec.sh` drust দিয়ে Windows-এ চালিয়ে দেখা হয়নি; test bash সরাসরি চালায়। বাকি file-গুলোর `PHP_OS_FAMILY` check এখনও আছে।
- D6: Queue, cache ও gateway reload Redis ছাড়া চলে, test-এ প্রমাণিত (`LocalProfileBackendsTest`)। Redis বন্ধ করে পুরো panel চালিয়ে দেখা হয়নি।

## E. macOS local

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| E1 | macOS `Paths`, `FileAccess`, `AccountManager` (local) | C1, C4 | Unit test পাস |
| E2 | Homebrew থেকে PHP ও MariaDB খোঁজা ও চালানো | C2, D6 | Supervisor সব service চালায় |
| E3 | Port 80/443 সমাধান | H4 | `http://localhost` সাধারণ user-এ কাজ করে |
| E4 | Local CA ও `.test` domain | E2 | Spec §6.12; Browser সতর্কতা ছাড়া `https://mysite.test` খোলে |
| E5 | Mail catcher | E2 | Spec §5; Laravel ও WordPress-এর mail panel-এ দেখা যায় |
| E6 | Site তৈরি থেকে মোছা পর্যন্ত E2E test | E3-E5 | WordPress ও Laravel site, backup, restore পাস |

## F. Windows local

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| F1 | Windows `Paths`, `FileAccess`, `AccountManager` (local) | B8, C1, C4 | Unit test পাস; `skip_until_windows_has("F1")` সরানো |
| F2 | php-cgi pool | B6, H1 | Spec §9; Crash ও recycle test পাস |
| F3 | PHP, MariaDB ডাউনলোড ও SHA-256 যাচাই | F1 | Manifest থেকে install; hash না মিললে থামে |
| F4 | Windows Service ও supervisor | C2 | Reboot-এর পর চালু হয় |
| F5 | Terminal: ConPTY | F1 | Web terminal-এ PowerShell চলে |
| F6 | hosts file ও local CA | F1 | Commit `4e360714`-এর `windows/dpanel.ps1`-এর logic reuse; একই E6 flow |
| F7 | Path edge case | D2 | Space, Unicode, long path, file lock test পাস |
| F8 | E2E test | F2-F7 | E6-এর সব flow Windows-এ পাস |

## G. Packaging

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| G1 | Tauri tray app | E6 | Start, Stop, Open, Status, Logs কাজ করে |
| G2 | macOS PKG/DMG, Intel ও Apple Silicon | G1 | পরিষ্কার Mac-এ install ও uninstall |
| G3 | Windows MSI | G1, F8 | পরিষ্কার Windows-এ install ও uninstall |
| G4 | Signing, notarization, release pipeline | G2, G3 | Signed build CI থেকে আসে |
| G5 | Auto-update ও rollback | G4 | ব্যর্থ update আগের version-এ ফেরে |
| G6 | Docs | G5 | `docs/windows.md`, নতুন `docs/macos.md` |

## H. Spike (go/no-go)

Spike-এর code ফেলে দেওয়ার জন্য, তাই repo-র বাইরে একটা scratch folder-এ রাখুন। শুধু ফল `plans/spikes/<id>.md`-এ লিখে `main`-এ commit করুন। Spec-এর default বদলালে spec-ও বদলান।

| ID | প্রশ্ন | Pass শর্ত | Fail হলে |
| --- | --- | --- | --- |
| H1 | Windows-এ gateway + php-cgi | WordPress-এ Linux FPM-এর অন্তত 50% request/s, 1 ঘণ্টা load-এ crash বা leak নেই | Pool-এর নকশা বদলানো; দরকারে Windows production plan আবার ভাবা |
| H2 | Windows site isolation | Spec §11-এর 1, 2, 4, 6, 7 পাস; এক server-এ 200টা আলাদা-user site | Production ঘনত্ব কমানো |
| H3 | Stalwart | Mailbox, alias, DKIM, quota, forward API দিয়ে; Gmail ও Outlook-এ delivery; Roundcube কাজ করে | প্রথম release mail ছাড়া; অন্য backend খোঁজা |
| H4 | macOS port 80/443 | সাধারণ user-এ পারে, নয়তো helper-এর নকশা | Helper বা `pf` redirect |
| H5 | macOS APFS ও limit | 300টা volume-এ স্থিতিশীল; reboot-এর পরে quota থাকে; supervisor limit কাজ করে | Quota-তে scan ও soft limit; capability `soft` |
| H6 | Windows NTFS quota | প্রতি user-এ hard limit, service account-এ কাজ করে | Scan ও soft limit |

পাসের সংখ্যাগুলো প্রথম লক্ষ্য। Spike শুরুর আগে দরকার হলে বদলান, শুরুর পরে নয়।

## I. Backend interface

Linux-এই আগে করা হবে, আচরণ অপরিবর্তিত রেখে।

| ID | কাজ | Depends | Files | Done |
| --- | --- | --- | --- | --- |
| I1 | `ResourceLimiter`, `DiskQuota`-র interface | A3 | Spec §6.6, §6.7 | Linux stub ও test |
| I2 | `FirewallBackend` (UFW, firewalld) | A3 | Spec §6.9; `dscript/repository/modules/firewall*` | Linux-এ rule add/remove |
| I3 | `DnsBackend` (PowerDNS) | A3 | Spec §6.10; `DnsRegistryService`, `DnsRecordReplacer`, `DnsZoneImporter`, `DnsController`, `DnsZoneFileController` | PowerDNS test পাস |
| I4 | `MailBackend` ও `PostfixDovecotBackend` | A5 | Spec §6.11; `EmailController`, `MailOutboundGate`, `MailboxDeliveryHealth`, `MailDeliveryDiagnosticsService`, `MailHostnameDetector`, `MailPasswordHash` | Mail flow অপরিবর্তিত |
| I5 | Rust `AcmeClient`, Linux-এ opt-in | C1 | Spec §6.12; `drust/src/ssl/`, `SslLifecycleService` | Let's Encrypt staging-এ issue ও renew |
| I6 | Gateway-তে `SiteState` | A3 | Spec §7; `edge_gateway/config.rs`, `dispatcher.rs` | Suspended site-এ `503` ও page; ACME চলে |

## J. Windows production

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| J1 | Windows user, group, ACL | F1, H2 | Spec §6.4, §6.2 |
| J2 | `IsolatedRunner` ও Job Object | J1, I1 | Spec §6.5, §6.6; Limit test পাস |
| J3 | NTFS `DiskQuota` | J1, H6 | Limit-এ লেখা আটকায় |
| J4 | Per-site php-cgi pool | F2, J2 | Spec §9; Site user হিসেবে চলে |
| J5 | Stalwart backend ও install | I4, H3 | E2E mail test; Roundcube |
| J6 | Windows DNS backend | I3 | Zone ও record আসল query-তে আসে |
| J7 | Defender Firewall backend ও brute-force watcher | I2 | Lock-out test; ban কাজ করে |
| J8 | ACME | I5 | আসল domain-এ issue ও renew |
| J9 | FTP/SFTP | J1 | `libunftp`; নিজের folder-এর বাইরে যায় না |
| J10 | ClamAV | J1 | Upload ও scheduled scan |
| J11 | Installer (server) ও update | G3, J1-J10 | Windows Server 2022 ও 2025-এ পরিষ্কার install |
| J12 | Backup ও migration Linux ⇄ Windows | C6, J1 | Linux-এর backup Windows-এ restore হয়, আর উল্টোটা |
| J13 | Security review ও penetration test | J1-J12 | Spec §11 পাস; গুরুতর সমস্যা শূন্য |

## K. macOS production

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| K1 | `dscl` user ও permission | E1, H5 | Spec §6.4 |
| K2 | APFS `DiskQuota` | K1 | Limit-এ লেখা আটকায়; plan বদলের flow |
| K3 | Supervisor limit | K1, I1 | Spec §10 |
| K4 | Stalwart, Roundcube | I4, J5 | E2E mail test |
| K5 | External DNS (Cloudflare) backend | I3 | Zone sync |
| K6 | `pf` firewall ও watcher | I2 | Lock-out test; ban কাজ করে |
| K7 | ACME, FTP/SFTP, ClamAV | I5, K1 | J8-J10-এর একই test |
| K8 | LaunchDaemon, reboot ও update flow | K1 | Reboot-এর পর সব চালু হয় |
| K9 | Reseller, WHMCS flow | K1-K3 | Provision, suspend, terminate পাস |
| K10 | Security review | K1-K9 | Spec §11 পাস; গুরুতর সমস্যা শূন্য |

## P. Feature gap: Enforcement

Linux আগে, তবে interface-এর পেছনে। [Feature plan](feature-gap-plan.md)-এর Phase 1।

| ID | কাজ | Depends | Files | Done |
| --- | --- | --- | --- | --- |
| P0 | Gap প্রমাণের failing test | - | `dpanel/tests/Feature/` | Suspended user-এর session, website ও limit-এর test লাল |
| P1 | `AccountSuspensionService` | P0, I6 | `UserManagementController`, `WhmcsController`, নতুন middleware | Panel ও WHMCS একই পথে; session revoke; website `503`; mail, FTP, cron, worker, SSH বন্ধ; `terminate`-এ 30 দিনের delete flow, আগে backup |
| P2 | Disk quota (Linux) | I1 | `ResourceQuotaService`, installer, Spec §7 | `report` mode-এ report; `enforce` mode-এ লেখা আটকায় |
| P3 | Bandwidth limit | I6 | `BandwidthUsageService`, `edge_gateway/bandwidth.rs` | মাসিক limit, action, reset |
| P4 | CPU/RAM limit | I1 | Spec §6.6; PHP-FPM pool config | Slice প্রয়োগ; panel-এ usage |
| P5 | Outbound mail limit ও queue UI | I4 | Rspamd ratelimit, `Pages/Email/Health/` | Limit ছাড়ালে থামে, alert; queue দেখা ও মোছা |
| P6 | Usage dashboard ও notification | P2-P4 | নতুন component, `NotificationService` | 80% ও 100%-এ notification |

## S. Feature gap: Security

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| S1 | WAF spike: libmodsecurity FFI বনাম Coraza | - | Latency মাপা; `plans/spikes/waf.md`-এ সিদ্ধান্ত |
| S2 | WAF implementation | S1 | Per-domain; default detection-only; CRS test |
| S3 | Firewall UI | I2 | Rule add/remove; lock-out test |
| S4 | Per-site log ও viewer | C1 | Rotation; panel-এ access ও PHP error log |
| S5 | Password-protected directory | - | Gateway-তে `WWW-Authenticate`; panel UI |
| S6 | Upload folder-এ PHP বন্ধ, hotlink, Cloudflare-only | - | Per-site setting |

## M. Feature gap: SSL, DNS, Mail

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| M1 | Autoresponder | I4 | Mailbox-এ Sieve; delivery test |
| M2 | Catch-all | I4 | Domain setting |
| M3 | Forward-only address | I4 | Mailbox ছাড়া forwarder |
| M4 | Wildcard SSL (DNS-01) | I5, I3 | PowerDNS ও Cloudflare-এ issue |
| M5 | Custom certificate upload | I5 | Key মিল যাচাই; মেয়াদের alert |
| M6 | DNSSEC | I3 | `gmysql-dnssec=yes` migration; DS record দেখায় |
| M7 | DNS secondary | I3 | NOTIFY ও AXFR |
| M8 | SMTP relay setting | I4 | Panel থেকে set |
| M9 | Mailing list | I4 | সবার শেষে |

## W. Feature gap: WordPress ও Backup

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| W1 | `wp-cli` ও WordPress তালিকা | C5 | সব site এক page-এ |
| W2 | Plugin, theme, core update | W1 | একটা ও bulk |
| W3 | Staging ও deploy | W1 | `CloneWebsiteJob`-এর ওপর |
| W4 | Security check | W1 | Report ও fix |
| W5 | Safe update | W2, W3 | Clone-এ পরীক্ষা; সমস্যা হলে production অপরিবর্তিত |
| W6 | Restic incremental ও encrypted backup | C6 | Restore point; key হারানোর সতর্কতা |
| W7 | Selective restore | W6 | একটা file, database, mailbox |
| W8 | Restore-test report | W6 | নির্ধারিত যাচাই |

## U. Feature gap: Platform

| ID | কাজ | Depends | Done |
| --- | --- | --- | --- |
| U1 | Scoped API token ও `/api/v1` | - | Website, DNS, database, mail endpoint; OpenAPI docs |
| U2 | Translation key ও `vue-i18n` | - | নতুন page key ব্যবহার করে; বাংলা ও English |
| U3 | পুরোনো page translate | U2 | Page ধরে ধরে |
| U4 | White-label | - | Logo, নাম, রং reseller-ভিত্তিক |
| U5 | Multiple IP | - | Site ও mail-এ IP বরাদ্দ |
| U6 | Update center UI | - | আগে বিদ্যমান অবস্থা যাচাই; panel থেকে update ও rollback |
| U7 | Extension system | U1 | Manifest, permission, sandbox নকশা আলাদা spec-এ |
| U8 | App catalog বাড়ানো | - | Nextcloud, PrestaShop, OpenCart, MediaWiki দিয়ে শুরু |

## Test matrix

| Test | Linux | Windows Server | macOS | Windows local | macOS local |
| --- | --- | --- | --- | --- | --- |
| `cargo test` | প্রতি commit | প্রতি commit | প্রতি commit | একই | একই |
| `php artisan test` | প্রতি commit | প্রতি commit | প্রতি commit | একই | একই |
| Spec §11 isolation | Nightly | Nightly | Nightly | §5 অংশ | §5 অংশ |
| E2E: site, DB, backup | Release | Release | Release | Release | Release |
| Mail delivery | Release | Release | Release | - | - |
| Load test | Release | Release | Release | - | - |
| Upgrade: আগের version থেকে | Release | Release | Release | Release | Release |

Linux distro matrix: Ubuntu, Debian ও RHEL পরিবার, বিশেষ করে P2, P4, I2, M6-এ।

## Milestone

| Milestone | Task | ফল |
| --- | --- | --- |
| M0 ভিত্তি | A, B | তিন OS-এ compile |
| M1 Linux enforcement | P, I (Linux) | Suspend ও limit কাজ করে |
| M2 macOS local | C, D, E | Mac-এ development |
| M3 Windows local | F | Windows-এ development |
| M4 Public local release | G | Installer সবার জন্য |
| M5 Go/no-go | H | Production-এর সিদ্ধান্ত |
| M6 Windows production | J | Windows Server-এ live |
| M7 macOS production | K | Mac-এ live |

H spike M2-এর সময়ই শুরু করা যায়, কারণ ফল আগে পেলে পরের ঝুঁকি কমে।
