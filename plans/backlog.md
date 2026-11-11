# Implementation Backlog

পুরো কাজের task তালিকা। প্রতিটা task ছোট, আলাদা commit-যোগ্য, আর তার শেষের শর্ত আছে। Technical contract: [platform-spec.md](platform-spec.md)।

**Status:** A1-A6, B1-B8, D1, D2 শেষ। C1, C2, C4, C5, C7, C9, C10, I1 ও I6-এর code শেষ, আর `1d63e22`-এ CI-র চারটা job সবুজ (Rust Linux 327, Windows 331, macOS 328 passed; Laravel Linux 653 passed, 13 skipped)। P0 শেষ। P1, P2, P3, S5, S6 ও I2-এর code শেষ, local-এ Linux test পাস (Rust 360, Laravel 691 passed, 13 skipped); CI-তে এখনও চলেনি। S3, S4, W1, W2, W3, W4 ও U2-এর code শেষ, D3 ও I3 আংশিক; local-এ Linux test পাস (Rust 382, Laravel 727 passed, 13 skipped), CI-তে এখনও চলেনি। C6, C8, I3, I4, M1, M2, M3, M8, W5 ও U4-এর code শেষ; local-এ Linux test পাস (Rust 396, Laravel 753 passed, 13 skipped), CI-তে এখনও চলেনি। P5-এর code শেষ; local-এ Laravel test পাস (763 passed, 13 skipped), Rust বদলায়নি; CI-তে এখনও চলেনি (নিচে দেখুন)। P4-এর code শেষ; local-এ Linux test পাস (Rust 407, Laravel 769 passed, 13 skipped), CI-তে এখনও চলেনি। S1 শেষ: WAF engine libmodsecurity 3, আলাদা worker process-এ ([spikes/waf.md](spikes/waf.md))। S2-এর code শেষ; local-এ Linux test পাস (Rust 413, Laravel 782 passed, 13 skipped), CI-তে এখনও চলেনি। P6-এর code শেষ, তাই Phase 1 (P0-P6)-এর code শেষ; local-এ Laravel test পাস (778 passed, 13 skipped), CI-তে এখনও চলেনি। I5-এর code শেষ, Linux-এ opt-in (`DRUST_SSL_ENGINE=acme`); local-এ Linux test পাস (Rust 431 passed), Laravel বদলায়নি; CI-তে এখনও চলেনি, আর আসল ACME server-এ এখনও চলেনি (নিচে দেখুন)। M5-এর code শেষ; local-এ Linux test পাস (Rust 437, Laravel 790 passed, 13 skipped), CI-তে এখনও চলেনি। C11 শেষ, D6 শেষ (`1d1fbec`); local-এ Linux test পাস (Rust 437, Laravel 790 passed, 13 skipped)। M6-এর code শেষ; local-এ Linux test পাস (Rust 441, Laravel 798 passed, 13 skipped), আর এই server-এর PowerDNS 4.8.3-এ sign, `delv` দিয়ে validate ও unsign চালিয়ে দেখা; CI-তে এখনও চলেনি। C3 আংশিক। F1-এর unit test পাস, F2 চলছে, H1-এর সিদ্ধান্ত হয়নি (নিচে দেখুন)। কাজের সময় `Depends`-এর ক্রম মেনে চলুন।

**খোলা বিষয়:**

- `skip_until_windows_has` আর code-এ নেই; কোনো test সেটা দিয়ে skip হয় না।
- C1: code-এর কাজ শেষ, CI সবুজ। `platform/`-এর বাইরে test ছাড়া যা থাকল: `/usr/bin/env` ও `PATH=` string (C5), `ALLOWED_ROOT_PREFIXES` (নিচে), আর `migration_ssh.rs`-এর remote server-এর path। Comment ও error message-এ `/etc/drust` লেখা আছে, সেগুলো code নয়। ClamAV এখন শুধু Linux-এ: `clamav` capability বন্ধ থাকলে scan চুপচাপ বাদ যায়, UI-তে কিছু দেখায় না। J10 ও K7-এ `timeout`-এর অন্য পথ বানিয়ে capability খুলতে হবে।
- C4: code-এর কাজ শেষ, CI সবুজ। `loginctl`, `pkill` এখন `AccountManager::end_sessions`-এ, আর `create_user.rs`-এর `chown`, `chmod`, `install` এখন `FileAccess` দিয়ে। Linux-এ আসল user-এর ওপর `end_sessions` চালিয়ে দেখা হয়নি।
- C2: code-এর কাজ শেষ, CI সবুজ। `platform/service.rs`-এ `ServiceManager`; C2-এর সব file-এ `systemctl` command আর নেই। থাকল: `api/terminal.rs`-এর blocklist (user-এর command আটকায়), দুটো comment। যেখানে `services` বন্ধ, সেখানে PostgreSQL, MongoDB ও Local LLM-এর route `404`, আর menu ও database page থেকে সেগুলো লুকানো; supervisor এলে capability খুলবে।
- C7: code-এর কাজ শেষ, CI সবুজ। `mariadb`, `mysql`, `mysqldump` `Paths`-এ। `binary_or_name` tool না পেলে নাম দেয়, তাই Linux-এর error আগের মতো। `database/postgresql.rs`-এর `runuser` C5-এর।
- I6: code-এর কাজ শেষ, CI সবুজ। `websites.serving_state` ও gateway-র `SiteState`। বন্ধ site-এ `503`, origin ও PHP চলে না, redirect ও edge cache-ও উত্তর দেয় না; ACME চলে। ACME-র পথ শুধু code পড়ে দেখা, test নেই। এই column এখনও কেউ বদলায় না; P1 বদলাবে ও gateway reload করবে।
- P0 শেষ। P0-র `max_storage_mb`-এর লাল test P2-তে সবুজ হয়ে `MailboxStorageQuotaTest`-এ গেছে; `EnforcementGapTest.php` আর `gap` group নেই। Bandwidth ও CPU/RAM-এর gap test নেই।
- P1: code-এর কাজ শেষ। হয়েছে: `AccountSuspensionService` (panel ও WHMCS একই পথে), session revoke, `EndSuspendedSessions` middleware, website-এ `503`, unsuspend-এ শুধু suspension-এর বন্ধ করা site খোলে, reseller suspend হলে তার customer-ও (`users.suspended_with_reseller`; নিজে আগে suspend হওয়া customer reseller unsuspend-এ খোলে না), আর cron: `active` job `suspended` হয় ও drust থেকে মোছে, unsuspend-এ শুধু সেগুলো ফেরে (drust না পেলে status তবু বদলায়, error report হয়)। Cron-এর edit form `suspended` status চেনে না। Queue worker: owner suspended থাকলে `LaravelQueueService::sync` কোনো worker চালায় না; row-এর `enabled` বদলায় না, তাই unsuspend-এ আগের enabled worker-গুলোই ফেরে। SSH ও SFTP: drust-এর `POST /accounts/{name}/suspend` system account-এর মেয়াদ শেষ করে (`usermod --expiredate 1`) ও session শেষ করে; `root`, `www-data`, uid 1000-এর নিচে কখনো নয়। আসল server-এ suspend করে SSH key-এর login আটকানো চালিয়ে দেখা হয়নি, আর account-এর আগের মেয়াদ থাকলে unsuspend সেটা মুছে দেয়। Mail: account-এর website-এর domain-এর ও তার নিজের বানানো `active` mailbox `suspended` হয় (Postfix ও Dovecot শুধু `active` দেখে, তাই login ও আসা mail দুটোই বন্ধ); unsuspend-এ শুধু `suspended`গুলো ফেরে, `disabled` থাকে। Panel-এর FTP account: `active` row `suspended` হয় আর drust একই `/accounts/{name}/suspend` দিয়ে সেই system account আটকায়। Cron-এর edit form এখন `suspended` চেনে: owner suspended থাকলে চালু করা job `suspended` থাকে, বন্ধ করা job `disabled`। `terminate` (WHMCS): suspend করে, `users.purge_after` = 30 দিন পরে; এর মধ্যে unsuspend করলে terminate বাতিল। রোজ `accounts:purge-terminated` প্রতিটা website আগে trash-এ archive করে (`WebsiteDeletionService`, website delete-এর একই পথ), তারপর FTP account, alias, mailbox ও user মোছে; কোনো website archive না হলে user থেকে যায়, পরের দিন আবার চেষ্টা। Trash archive 15 দিন থাকে (`serverpanel:trash-backups-prune`)। Mailbox-এর maildir site home-এর বাইরে থাকলে মোছে না। Reseller terminate হলে তার customer শুধু suspend হয়, terminate হয় না। আসল server-এ চালিয়ে দেখা হয়নি।
- I1: code-এর কাজ শেষ, CI সবুজ। `platform/limits.rs`-এ `ResourceLimiter`, `DiskQuota`, আর সব OS-এর জন্য `NoLimits` stub। `DiskQuota`-র Linux backend P2-তে এসেছে; `ResourceLimiter` এখনও কেউ ডাকে না (P4)।
- P2: code-এর কাজ শেষ। `platform/unix/quota.rs`-এ `LinuxQuota`: usage sites root হেঁটে uid ধরে গোনে (hard link একবার, symlink follow করে না), limit `setquota -a` দিয়ে (filesystem-এ `usrquota` না থাকলে ব্যর্থ)। Linux server ছাড়া `NoLimits`। drust-এ `PUT /accounts/{name}/limits` ও `GET /accounts/{name}/usage`, uid 1000-এর নিচে কখনো নয়। Panel: `package_plans.enforcement_mode` (default `report`), `account_usage_samples`, ঘণ্টায় `accounts:disk-check`; `report`-এ সীমা পেরোলে একবার notification ও আগের limit তুলে নেয়, `enforce`-এ প্রতি system user-কে নিজের usage + package-এর বাকি জায়গা পর্যন্ত hard limit। Mailbox-এর `quota_mb` এখন package storage-এর মধ্যে মোট হিসাবে গোনা। `/capabilities`-এর `disk_quota` এখনও `setquota` আছে কিনা দেখে না। আসল quota-চালু filesystem-এ চালিয়ে দেখা হয়নি।
- P3: code-এর কাজ শেষ। `package_plans.over_bandwidth_action`: `notify` বা `block`; spec-এর `throttle` gateway পারে না, তাই UI-তে নেই। প্রতি 15 মিনিটে `accounts:bandwidth-check` gateway-র মাসিক file থেকে account-এর সব site ও alias-এর traffic গোনে; মাসে একবার notification; `block`-এ `active` site `over_limit` হয় (gateway `503`), নতুন মাসে বা limit সরালে শুধু `over_limit`গুলো `active` হয়। `max_bandwidth_gb = 0` মানে সীমাহীন। Gateway-র `bandwidth.rs` বদলায়নি।
- S2: code-এর কাজ শেষ। drust libmodsecurity-কে link করে না, `dlopen` করে (`platform/unix/modsecurity.rs`), তাই না থাকলে build ও চলা দুটোই আগের মতো, শুধু `waf` capability বন্ধ। Engine চলে শুধু `drust waf-worker` process-এ: root হিসেবে `/etc/drust/waf/main.conf` পড়ে, তারপর `nobody` হয়ে যায়; gateway-র সঙ্গে stdin/stdout-এ length-prefixed frame (Unix socket নয়, তাই Windows-এও একই code)। Worker কয়টা: core-এর অর্ধেক (`DRUST_WAF_WORKERS`)। শুধু PHP, proxy ও port share-এ যাওয়া request; static, edge cache hit, ACME আর panel-এর `system` site বাদ। 128 KB পর্যন্ত body আগে পুরো পড়ে আবার request-এ বসায়; বড় বা chunked body শুধু header দিয়ে দেখা হয়, upload stream থাকে। 500 ms-এ উত্তর না এলে বা worker মরলে request যায় (fail-open), log-এ warning; worker নতুন করে চলে। Site প্রতি `website_guard_settings.waf_mode`: `off`, `detect` (default, row না থাকলেও), `block` (403)। Match `<logs>/sites/<domain>/waf.log`-এ এক line-এ (rule id, msg, data), Website → Logs-এ "Firewall" tab; Rules → Site Protection-এ mode বাছাই। `dscript` `waf` module: `libmodsecurity3t64`/`libmodsecurity3` (RHEL-এ EPEL-এর `libmodsecurity`), CRS 4.30.0 GitHub থেকে SHA-256 মিলিয়ে, নিজের `modsecurity.conf` (engine সবসময় On; block বা শুধু log drust ঠিক করে), worker দিয়ে rule load পরীক্ষা, gateway restart। এই PC-তে আসল libmodsecurity ও CRS দিয়ে worker binary চালিয়ে দেখা: clean request 200, SQLi ও XSS 403, প্রতিটা 2-3 ms। দেখা হয়নি: আসল gateway-তে site-এর ভেতর দিয়ে, module-এর install (root), RHEL, আর CRS-এর নিজের test (go-ftw), তাই false positive-এর হিসাব নেই। বড় JSON (180টা field-এ 40 ms) এখনও কমানো হয়নি।
- P6: code-এর কাজ শেষ। `UsageAlertService`: disk, মাসিক bandwidth, memory ও CPU-তে package limit-এর 80%-এ `warning` ও 100%-এ `blocked` notification, account (ও তার reseller)-কে। প্রতিটা level একবার; আবার যায় শুধু use তার 10 point নিচে নামার পর (line-এর কাছে ঘোরাফেরায় বারবার যায় না), আর bandwidth-এ নতুন মাসে। একবারে দুটো line পেরোলে শুধু 100%। CPU-র গড় quota-র ওপরে যায় না, তাই limit-এর 95%-কে 100% ধরা। অবস্থা `usage_alerts` table-এ; P2 ও P3-এর `users.disk_over_limit_at` ও `bandwidth_over_limit_month` সরানো, তাদের 100%-এর notice এখন এই service দিয়ে (type ও title আগের মতো)। Mail limit-এর alert P5-এর নিজের। Hosting → "Usage & Limits" page (`UsageController`): admin সব packaged account, reseller নিজে ও customer, বাকিরা নিজে; প্রতি resource-এর শেষ reading, 80%/100%-এ রং, পাঠানো alert, আর account খুললে সাত দিনের ঘণ্টাপ্রতি গড়ের ছোট graph (SVG, chart library নেই)। Notification-এর তালিকা ও পাতায় `warning` status হলুদ।
- P5: code-এর কাজ শেষ। Package-এ `max_emails_per_hour` ও `max_emails_per_day` (default 0 = সীমাহীন, তাই পুরোনো package-এ কিছু বদলায় না)। গোনে Rspamd-এর নিজের Lua plugin (`dpanel_ratelimit.lua`, rspamd module-এর `install.sh`-এর ভেতরে, কারণ module একটা file হিসেবেও আসে); Rspamd-এর built-in `ratelimit` package-ভিত্তিক মান panel-এর table থেকে নিতে পারে না। Account-এর ভাগে পড়ে: SMTP login-এর mailbox, account-এর domain (alias-সহ), আর site-এর system user (`user:<site_owner>`, PHP `mail()`-এর default sender)। প্রতি recipient একটা, fixed UTC ঘণ্টা ও দিন, Redis-এ। শুধু outbound গোনে: login করা client, বা sendmail (client address নেই বা loopback); অন্য server-এর mail কখনো নয়, null sender-ও নয়। সীমা পেরোলে `soft reject`: client-কে পরে চেষ্টা করতে বলে, sendmail-এর mail queue-তে থাকে। Redis না পেলে mail ছেড়ে দেয়। PHP `mail()` ধরতে rspamd module এখন `non_smtpd_milters`-এও বসে, তাই local mail-ও spam scan হয় (score 15+ হলে ফেরত যায়)। Panel প্রতি 5 মিনিটে `mail:send-limits` দিয়ে map লেখে (`mail-send-limits.sh`, root; বদল না হলে লেখে না, ঘণ্টায় একবার আবার) ও Redis-এর `hit` key দেখে প্রতি ঘণ্টা বা দিনে একবার notification দেয়। Mail Health-এ admin-এর "Sending limits" tab ও queue-তে মোছা ও retry (`mail-queue.sh`, `postsuper -d`)। Queue-র তালিকা আগের মতো `postqueue -p` থেকে; spec-এর `queue(): MailQueueView` হয়নি, শুধু `deleteQueued` ও `retryQueue`। Lua-র syntax ও Redis script local Redis-এ চালিয়ে দেখা; আসল Rspamd-এ plugin load হয় কিনা (`lua.local.d`), sendmail-এর mail milter-এ কোন IP নিয়ে আসে, আর `soft reject`-এ pickup-এর mail সত্যিই queue-তে থাকে কিনা দেখা হয়নি, কারণ এই PC-তে Rspamd নেই। Account-এর বাইরের sender (`-f` দিয়ে অন্য domain) গোনা হয় না।
- P4: code-এর কাজ শেষ। সিদ্ধান্ত: যে account-এর package-এ limit আছে, শুধু তার site user-দের জন্য আলাদা PHP-FPM master; বাকিরা আগের মতো shared master-এ। Package-এ `max_cpu_percent` (100 = এক core), `max_memory_mb`, `max_processes`, default 0 = সীমাহীন। `ResourceLimiter` এখন system user নয়, account group (`a<user id>`) ধরে, কারণ এক account-এর কয়েকটা site user থাকতে পারে আর limit সবার মিলিয়ে। Linux-এ `SystemdSlices`: `/etc/systemd/system/dpanel-a<id>.slice` (`CPUQuota`, `MemoryMax`, `TasksMax`) আর চলমান slice-এ `systemctl set-property --runtime`; cgroup v2 না থাকলে `none`। `edge_gateway/php_fpm_master.rs`: site user-এর pool `pool.d` থেকে `/etc/php/<v>/fpm/dpanel-dedicated/<owner>.pool`-এ যায়, `dpanel-php<v>-fpm-<owner>.service` (`Slice=dpanel-a<id>.slice`, `Type=notify`) সেটা চালায়; socket একই, তাই gateway-র কিছু বদলায় না। Shared master reload হয়ে socket ছাড়ার পরে নতুন master শুরু হয়, তাই মাঝে এক-দুই সেকেন্ড site-এর PHP থামে; সেই সময়ের request provision lock-এ অপেক্ষা করে। কিছু ব্যর্থ হলে সব shared master-এ ফেরে। `<state_dir>/fpm-dedicated/<owner>` মনে রাখে, তাই site নতুন PHP version-এ গেলে সেটাও নিজের master-এ যায়। drust-এ `PUT`/`DELETE /resource-limits/{group}`, `GET /resource-limits/{group}/usage` (CPU আগের call থেকে এখন পর্যন্ত গড়)। Panel প্রতি 5 মিনিটে `accounts:resource-check`: limit বা site user বদলালে তবেই পাঠায় (`users.resource_limits_applied`), package থেকে limit সরালে একবার `DELETE`, আর CPU ও memory `account_usage_samples`-এ; dashboard-এ CPU ও Memory দেখায় যখন package limit দেয়। Generated master config আসল `php-fpm8.3`-এ root ছাড়া চালিয়ে দেখা (`--test`, socket আসে)। Root দিয়ে systemd unit, slice-এর limit, আর shared থেকে নিজের master-এ সরানো আসল server-এ দেখা হয়নি। শুধু PHP slice-এ যায়: SSH, cron, Node/Python app ও queue worker এখনও account-এর slice-এর বাইরে (C3-এর unit-এ `Slice=` দিলে আসবে)। Limit-এর notification P6-এর।
- S5, S6: code-এর কাজ শেষ। Rules → Site Protection page। Gateway-র `site_guards.rs` redirect ও edge cache-এর আগে চলে: password-protected folder (`basic_auth.rs`, `WWW-Authenticate: Basic`, hash `s256i` = salted SHA-256 20000 round, কারণ drust-এ bcrypt crate নেই; একবার মিলে যাওয়া header মনে রাখে), PHP বন্ধ folder (`.php`, `.phtml`, `.phar` ইত্যাদি যেকোনো segment-এ `403`), hotlink (অন্য site-এর referrer থেকে media `403`, referrer না থাকলে চলে), আর Cloudflare-only (connection-এর নিজের address Cloudflare-এর range-এ না থাকলে `403`; header বিশ্বাস করে না; range code-এ লেখা)। Path decode করে, ছোট হাতের করে মেলায়। ACME challenge সবসময় খোলা। Alias তার main site-এর setting নেয়। Protected folder-এ edge cache বন্ধ। আসল gateway-তে browser দিয়ে দেখা হয়নি।
- I2: code-এর কাজ শেষ। `platform/firewall.rs`-এ `FirewallBackend`: UFW (deny `prepend` দিয়ে প্রথমে), firewalld (rich rule, running ও `--permanent` দুটোতে), অন্য OS-এ `Unsupported`। Rule-এর id তার কাজের hash, তাই কিছু জমাতে হয় না; নিজের লেখা ধরনের rule-ই শুধু list হয়। drust-এ `GET`/`POST /firewall/rules`, `DELETE /firewall/rules/{id}`। `ban`-এর `ttl` এখনও রাখা হয় না। Lock-out timer ও `/firewall/confirm` S3-এর; panel এখনও এই endpoint ডাকে না। আসল UFW বা firewalld-এ চালিয়ে দেখা হয়নি (root লাগে)।
- S3: code-এর কাজ শেষ। Security → Firewall Rules page (শুধু admin)। drust-এর প্রতি add ও remove `confirm_token` দেয়; 60 সেকেন্ডে `POST /firewall/confirm` না এলে drust বদলটা উল্টে দেয় (add-এ rule সরায়, remove-এ ফেরায়)। আগে থেকে থাকা rule আবার add করলে timer চলে না। Page countdown দেখায়, "রাখুন" চাপলে confirm যায়। Browser-এর পুরোনো keep-alive connection নতুন rule-এর পরেও পৌঁছাতে পারে (UFW established connection ছাড়ে), তাই নিজেকে আটকানো rule-ও confirm হয়ে যেতে পারে। Timer drust-এর memory-তে: drust restart হলে বাকি undo হারায়। Spec-এর `firewall_rules` table বানানো হয়নি, কারণ rule-এর id hash থেকে আসে। আসল UFW-তে চালিয়ে দেখা হয়নি।
- S4: code-এর কাজ শেষ। Gateway প্রতি request-এ `<data_dir>/logs/sites/<domain>/access.log`-এ combined format ও millisecond লেখে, আলাদা thread-এ (queue ভরলে line বাদ যায়); 10 MB-এ rotate, পাঁচটা পুরোনো file থাকে। নতুন PHP-FPM pool account-এর error `<site home>/logs/php-error.log`-এ লেখে; আগের pool-এ এই line নেই, pool আবার না বানানো পর্যন্ত PHP আগের জায়গায় log করে। Gateway প্রতি 10 মিনিটে PHP log rotate করে। drust-এ `GET /sites/logs` শুধু এই দুটো জায়গা পড়ে। Website → Logs page-এ filter, শেষ N line, follow। Alias-এর request main site-এর canonical domain-এ যায় কিনা আসল gateway-তে দেখা হয়নি।
- W1, W2, W4: code-এর কাজ শেষ। Hosting → WordPress page-এ user-এর সব WordPress site (`wp-includes/version.php` আছে এমন primary site)। drust-এ `POST /api/v1/wordpress`: নির্দিষ্ট কয়েকটা wp-cli command, site user হিসেবে, সবসময় `--skip-plugins --skip-themes`; plugin/theme-এর নাম শুধু slug। একটা বা bulk update (browser একটার পর একটা site চালায়)। Security check: core ও plugin checksum, সহজ admin নাম, খোলা registration, `WP_DEBUG`, `DISALLOW_FILE_EDIT`, বাকি update; চারটা fix। Installer এখন `/usr/local/bin/wp`-এ wp-cli বসায় (SHA-512 যাচাই)। আসল WordPress-এ wp-cli চালিয়ে দেখা হয়নি।
- W3: code-এর কাজ শেষ। দুটো site জোড়া (`wordpress_stagings`): production ও staging। Copy ও deploy দুটোই `CloneWebsiteJob` (target আগে trash-এ যায়), তারপর `WordpressStagingFinishJob` `wp search-replace` দিয়ে address বদলায় (scheme, port ও path source-এর মতো থাকে)। Staging site আগে থেকে বানাতে হয়; panel নিজে বানায় না। Deploy পুরো site ও database বদলায়, তাই staging-এর পরে production-এ আসা order বা comment হারায়; W5-এ ভাবতে হবে।
- U2: code-এর কাজ শেষ। `vue-i18n` 11, `resources/js/i18n/locales/{en,bn}.json`, sidebar-এর নিচে ভাষা বাছাই (browser-এ মনে রাখে)। এই round-এর নতুন page (Firewall, Logs, WordPress) key ব্যবহার করে। `TranslationKeysTest` দেখে দুই ভাষায় একই key আছে, page-এর `t('…')` key আছে, placeholder মেলে। Template literal দিয়ে বানানো key test ধরে না। পুরোনো page U3-এর।
- D3 আংশিক। drust-এ `GET /system/overview` (hostname, IP, OS, uptime, core, CPU model, load, memory, swap, disk, process, network, service state) ও `GET /system/services`। Dashboard এখন কোনো `shell_exec`, `/proc` বা `systemctl` চালায় না; account-এর disk P2-এর hourly sample থেকে। `SystemdStackServiceManager` drust থেকে। Monitoring-এর service, CPU, memory, swap, uptime drust থেকে; বাকি: physical disk ও mount (`/sys/block`, `/proc/mounts`), sensor, CPU frequency, `lscpu`, `dmidecode`, `ps`-এর top process, `/proc/net/*`-এর socket ও connection। Windows-এ disk space এখন `GetDiskFreeSpaceExW` দিয়ে (C8), CI-তে এখনও চলেনি।
- I3: code-এর কাজ শেষ। `DnsBackend`-এ এখন একটা একটা record-ও: `addRecord`, `updateRecord`, `deleteRecords`, `touchZone` (serial)। `DnsController`, `DnsRecordReplacer`, `DnsZoneImporter` ও `MailDnsZoneWriter` PowerDNS-এর `records` আর সরাসরি লেখে না; পড়ে এখনও সরাসরি (id খোঁজা, duplicate ও CNAME check)। `DnsZoneFileController` আগে থেকেই শুধু পড়ত। `WebsiteDnsProvisionService` শুধু panel-এর `dns_zones`/`dns_records` লেখে, PowerDNS-এ কিছুই যায় না, আর A record-এ hostname বসায়; backend দিয়ে চালালে যা serve হয় তা বদলাবে, তাই ছোঁয়া হয়নি, আলাদা task দরকার। Spec বলে `DnsZone`/`DnsRecord` source of truth; এখনও আসলে PowerDNS-এর table।
- C6: code-এর কাজ শেষ। `app/support/archive.rs`: tar (plain, gzip, zstd; magic byte দেখে), zip, list, পুরো বা একটা member extract, member পড়া, decompress, `cp -a`-এর বদলে `copy_tree` (symlink link থাকে, mode ও mtime থাকে, owner caller-এর)। Extract-এর আগে পুরো archive দেখে: link, device, absolute বা `..` থাকলে কিছুই লেখে না; setuid bit যায়। drust-এর নিজের cpmove backup-এ link থাকে (Laravel `public/storage`), তাই সেখানে `links: true`, তবু প্রতিটা entry target-এর ভেতরে। cpmove backup এখন home আগে copy না করে সরাসরি archive-এ যায় (`homedir/`); format GNU tar-এর মতো, test-এ `tar -tzf` দিয়ে পড়া। `migration.rs`, `migration_archive.rs` ও `api/website/`-এ `tar`, `unzip`, `zipinfo`, `gzip`, `zstd`, `cp` আর নেই; `migration_archive.rs`-এর test এখনও আসল `tar` দিয়ে archive বানায়, যাতে GNU tar-এর archive পড়া প্রমাণ হয়। `zstd` crate নতুন direct dependency (আগেই tower-http দিয়ে build হত)। আসল cPanel, Plesk ও DirectAdmin backup দিয়ে চালানো হয়নি।
- C8: code-এর কাজ শেষ। `api/website.rs` এখন `api/website/` (`mod`, `clone`, `zip_archive`, `cpmove`, `progress`, `restore`)। `mysql`/`mysqldump` `database/cli.rs`-এ (password `MYSQL_PWD`-এ, argument-এ নয়), `df` এখন `platform::disk_space` (Windows-এ `GetDiskFreeSpaceExW`, তাই D3-এর Windows disk space-ও এল)। এই module-এ `Command::new` নেই; `pipe_file_into` caller-এর বানানো `Command` নেয়। MSVC-তে `cargo check` এখানে চালানো যায়নি (local-এ MSVC নেই), CI দেখবে।
- I4: code-এর কাজ শেষ। `App\Services\Mail\Backend\MailBackend` ও `PostfixDovecotBackend`, `config('mail.backend')` দিয়ে। `EmailController`-এর Maildir create/move/remove ও domain prep এখন এর মধ্য দিয়ে। Spec-এর `dkimRecord`, `health`, `queue` এখনও interface-এ নেই: DKIM `MailDomainProvisioner`/`MailDnsRecords`-এ, health `MailHealthController`-এ, queue P5-এর।
- M1: code-এর কাজ শেষ। `mailbox_autoresponders`; drust-এর `/api/v1/mailbox-storage`-এ `autoresponder` action Sieve `vacation` script বানায় (text escape, subject-এর line break বাদ, তারিখ `YYYY-MM-DD` যাচাই, `:days` 1-30) আর Maildir-এ `.dovecot.sieve` হিসেবে site user-এর নামে লেখে; link থাকলে আগে সরায়। Rspamd module-এর Sieve config এখন personal script চালায় (2.3 ও 2.4 দুই syntax)। ওই config না থাকলে UI-তে নেই, update `404`। Spam script আগে চলে ও `stop` করে, তাই spam-এ উত্তর যায় না। আসল Dovecot-এ চালিয়ে দেখা হয়নি।
- M2, M3: code-এর কাজ শেষ। `mail_forwarders` ও `mail_domains.catch_all_to`; Email → Forwarders & Catch-all page। Installer-এর Postfix query: domain-এ forwarder থাকলেও mail নেয়; alias query প্রতিটা mailbox-এর জন্য নিজেকে ফেরায় (না হলে `@domain` catch-all আসল mailbox-এর mail নিয়ে নিত; suspended mailbox তবু `virtual_mailbox_maps`-এ আটকায়)। Migration না চললে আগের query-তে ফেরে, যাতে mail defer না হয়। Query scratch MariaDB-তে চালিয়ে দেখা, আসল Postfix-এ নয়। Package-এর `allow_forwarding` দুটোতেই লাগে। Catch-all-এর domain-এ mailbox বা forwarder থাকতে হয়।
- M8: code-এর কাজ শেষ, server-ব্যাপী (Hestia-র মতো domain প্রতি নয়)। `dscript/scripts/mail-smtp-relay.sh`: `relayhost`, SASL password map (0600), TLS বাধ্যতামূলক; বন্ধ করলে `smtp_tls_security_level=may` ফেরে। Password environment-এ যায়, panel রাখে না। Email → SMTP Relay page (শুধু admin)। আসল Postfix-এ চালিয়ে দেখা হয়নি।
- W5: code-এর কাজ শেষ। WordPress → Updates-এ "নিরাপদ update": production staging-এ copy (W3-এর chain), তারপর `WordpressSafeUpdateJob` staging-এ update চালায় ও দেখে home ও `wp-login.php` 5xx বা WordPress-এর critical error page দেয় কিনা (request এই server-এর 127.0.0.1-এ, তাই staging-এর নাম public DNS-এ না থাকলেও চলে), আর PHP log-এ staging-এর নিজের path-এর নতুন fatal আছে কিনা। সব ঠিক হলে তবেই production update হয়, তারপর production-ও একইভাবে দেখে (খারাপ হলে জানায়, rollback করে না)। Update-এর আগেই staging ভাঙা থাকলে কিছু বদলায় না। আসল WordPress-এ চালিয়ে দেখা হয়নি।
- U4: code-এর কাজ শেষ। `brandings` (owner_id null = server-এর, নয়তো reseller-এর); System → Branding page। Reseller ও তার customer reseller-এর নাম, logo, রং দেখে; খালি অংশ server-এর থেকে, তারপর dPanel-এর। Logo PNG/JPG/WebP (SVG নয়, script থাকতে পারে), 512 KB; login page-এর জন্য public route। রং এখন শুধু sidebar-এর ওপরে একটা দাগ; পুরো theme বদলায় না। Login page প্রথমে server-এর branding দেখায়, কারণ তখনও user জানা নেই।
- I5: code-এর কাজ শেষ, কিন্তু `Done`-এর শর্ত (Let's Encrypt staging-এ issue ও renew) এখনও পূরণ হয়নি। `platform/acme/`-এ `AcmeClient` ও `Rfc8555Client`: RFC 8555 নিজে লেখা (`ring`-এর ES256, `rcgen`-এর CSR, `x509-parser` দিয়ে certificate পড়া; তিনটাই নতুন direct dependency, `ring` আগেই build হত)। শুধু HTTP-01। Linux-এ default certbot-ই; `DRUST_SSL_ENGINE=acme` দিলে `ssl/ensure` নিজের client দিয়ে `/etc/drust/tls/<domain>.crt`-এ issue করে, panel-এর কিছু বদলায়নি। Token `data_dir/acme/.well-known/acme-challenge/`-এ, gateway সব hostname-এর জন্য সেখান থেকে দেয় (spec-এর "memory থেকে" হয়নি, কারণ gateway আলাদা process; spec বদলানো)। ভালো certbot certificate থেকে যায়, client নেয় renew-এর সময়ে, নতুন site-এ ও reinstall-এ; certbot-এর timer আগে renew করলে site certbot-এই থাকে, তাই সব site সরাতে চাইলে `certbot.timer` বন্ধ করতে হবে। `ssl/remove` শুধু client-এর নিজের issue করা certificate মোছে। নতুন `POST /api/v1/ssl/renew` (`renew_due`); panel এখনও ডাকে না, কারণ রোজের `serverpanel:ssl-reconcile` `ssl/ensure` দিয়েই renew করে। `ssl/ensure` ও `ssl/remove` এখন blocking thread-এ চলে। Test: test-এর ভেতরের একটা ACME server প্রতিটা JWS signature, nonce ও URL যাচাই করে, token folder থেকে পড়ে, আর CSR sign করে; issue, ব্যর্থ validation, renew, revoke, stale nonce পাস। দেখা হয়নি: Pebble বা Let's Encrypt staging (এই PC-তে বাইরের binary চালানোর অনুমতি ছিল না, আর staging-এ public domain লাগে), তাই `processing` অবস্থার অপেক্ষা ও আসল CA-র chain কখনো চলেনি; আসল gateway-তে token; Windows ও macOS-এ build। `a_real_acme_server_issues_renews_and_revokes` test ignored, Pebble বা staging-এর বিরুদ্ধে হাতে চালানোর জন্য। বাকি: `acme_orders` table নেই; `revoke` কেউ ডাকে না; mail hostname ও installer-এর certificate এখনও certbot; certbot-এর পথ ও IP certificate এখনও `openssl` program চালায়, আর gateway reload `redis-cli` দিয়ে, তাই Windows ও macOS-এ J8/K7-এ সেগুলো বদলাতে হবে; local profile-এর `local_ca` বানানো হয়নি (E4, F6)।
- M5: code-এর কাজ শেষ। Website → Manage-এ "Upload certificate" (`CustomCertificateButton.vue`): certificate, private key ও ঐচ্ছিক CA bundle, লেখা বা file থেকে। drust-এর `POST /api/v1/ssl/install` বসানোর আগে দেখে certificate এই domain-এর, মেয়াদ আছে, আর key মেলে; রাখে `/etc/drust/tls/custom/`-এ, যেটা gateway সব engine-এ আগে নেয়। রোজের reconcile সেটা renew বা বদলায় না; "Reinstall SSL" নতুন certificate issue হওয়ার পরে upload মোছে, "Uninstall SSL"-ও মোছে। Panel key রাখে না। মেয়াদের 30, 7 ও 1 দিন আগে ও শেষ হলে owner (ও তার reseller) একবার করে notification পায় (`CustomCertificateExpiryAlert`, `serverpanel:ssl-reconcile`-এর শেষে, দিনে দুবার)। দেখা হয়নি: browser-এ form, আর আসল gateway-তে upload করা certificate serve হওয়া। CA trusted কিনা বা chain-এর ক্রম (leaf-এর পরে) যাচাই হয় না। `www.` alias main domain-এর upload ব্যবহার করলে alias-এ "Reinstall SSL" সেটা সরায় না; main domain থেকে সরাতে হয়। IP-র site ও `.test`-এর মতো নামে upload হয় না। SSL বন্ধ করলে file থেকে যায়, আবার চালু করলে সেটাই ফেরে।
- Audit (2026-11-11): `65f8462`-এর CI-তে Windows ও macOS লাল ছিল, কারণ `system_accounts_are_never_limited` ধরে নিয়েছিল `root` uid 0; অন্য OS-এ lookup `501` দেয়। Test এখন Linux-এ বার্তা দেখে, বাকি OS-এ শুধু দেখে request ফেরানো হয়েছে। `cargo fmt --check` 129টা file-এ পার্থক্য দেখায় (CI চালায় না, CONTRIBUTING বলে চালাতে); একবারে format করা আলাদা commit-এর সিদ্ধান্ত।
- C11: `.github/scripts/platform-guard.sh`, CI-র Rust (Linux) job-এ। `platform/`-এর বাইরে OS-এর `cfg` থাকলেই fail। Linux path (`"/etc/..."` ইত্যাদি, test ও comment বাদে) প্রতি file-এ `platform-guard-baseline.txt`-এর সংখ্যার বেশি হলে fail; path সরালে `--update` দিয়ে সংখ্যা কমান, বাড়াবেন না।
- D6: `LocalProfile` local profile-এ Redis-এর queue ও cache database-এ নেয় (`LocalProfileBackendsTest`)। সরাসরি Redis-এর call (`EdgeGatewayReloader`, `MailSendCounters`, dashboard ping) Redis না পেলে fallback বা `null` দেয়।
- M6: code-এর কাজ শেষ। DNS zone drawer-এ "DNSSEC" অংশ (`ZoneDnssec.vue`): চালু/বন্ধ, registrar-এর জন্য DS record copy; server sign করতে না পারলে (`501`) অংশটা লুকায়। drust-এর `/api/v1/dns/dnssec/{zone}` `pdnsutil` চালায়: ECDSA P-256 CSK, narrow NSEC3 `1 0 0 -` (RFC 9276), তারপর rectify; narrow বলে `ordername` লাগে না। Sign করা zone-এর record বদলালে `PowerDnsBackend` rectify চায় (ব্যর্থ হলে log, change থাকে); `deleteZone` key ও metadata মোছে। Installer দুটো এখন `gmysql-dnssec=yes` লেখে, আর dns module-এর `update` সেটা পুরোনো server-এও লেখে। `DnsController`-এর fallback-এ বানানো database-এ DNSSEC table নেই, সেখানে zone unsigned ধরা হয়। আসল PowerDNS-এ `signs_and_unsigns_a_real_zone` (ignored, root লাগে) পাস; panel থেকে চলমান drust দিয়ে পুরো পথ চালানো হয়নি (installed drust পুরোনো build)। Key rollover নেই; Ubuntu ছাড়া অন্য distro-তে দেখা হয়নি।
- U8 শুরু হয়নি: চারটা app-এর আলাদা installer, আর `AppInstallService` আগেই বড়; আগে app প্রতি আলাদা class-এ ভাগ করা দরকার।
- `MainWebsiteController::destroy`-এর primary site মোছার কাজ এখন `WebsiteDeletionService`-এ; controller শুধু response বানায়।
- C5: code-এর কাজ শেষ, CI সবুজ। `platform/runner.rs`-এ `IsolatedRunner` ও environment allowlist। আগে `dependencies`, `rust_process`, `git_deploy`-র `runuser` drust-এর পুরো environment child-এ দিত, তাই customer-এর script `DRUST_API_TOKEN` পড়তে পারত। আসল `runuser` root ছাড়া চালিয়ে দেখা হয়নি। `git_deploy`-র `--preserve-environment` গেছে, তাই `HOME` এখন passwd থেকে। Terminal-এর PTY `runuser` থাকল (`bwrap --clearenv`)।
- C3 শেষ নয়। `node_process.rs`, `python_process.rs`, `rust_process.rs`, `php.rs`-এর `systemctl` ও `journalctl` এখন `ServiceManager`-এ। বাকি: এই file-গুলোতে ও `laravel_queue.rs`-এ systemd unit file লেখা ও `/etc/systemd` path, আর `Done`-এর শর্ত, কারণ supervisor (E2, F4) এখনও নেই।
- C9: code-এর কাজ শেষ, CI সবুজ। `ssh_key.rs`-এর `chown`/`chmod` ও `git_deploy.rs`-এর clone folder-এর `chown` এখন `FileAccess` দিয়ে, `open_permissions`-এর `chmod -R 777` এখন `fs::set_mode_tree` (symlink follow করে না)। `git`, `ssh-keygen`, `journalctl` `Paths`-এর `Tool` থেকে। `docker.rs` ও `fail2ban.rs`-এর client আগেই `Paths` থেকে আসত; `api/ftp.rs`-এ কোনো command ছিল না। `git_deploy.rs`-এর `cfg!(test)`-এর `git` শুধু test-এর পথ। `open_permissions` `file_permissions` capability বন্ধ থাকলে (Windows) UI-তে দেখায় না, আর panel `404` দেয়।
- C10: code-এর কাজ শেষ, CI সবুজ। `platform/scheduler.rs`-এ `Scheduler`; Linux-এ `/etc/cron.d` আগের মতো, Windows ও macOS-এ drust-এর timer (`state_dir/cron-jobs.json`)। Parser, overlap ও store-এর test পাস। আসল drust চালিয়ে Windows-এ job চলতে দেখা হয়নি। Panel-এর বানানো command এখনও Unix shell-এর (`cd ... && php artisan ... >> /dev/null 2>&1`), তাই Windows-এর `cmd /C`-এ `/dev/null` অংশ ভাঙবে; command portable করা D4-এর কাজ। Windows server-এ `NoRunner`, তাই সেখানে job চলে না (J2)।
- `chown` ও `chmod`-এর অন্য call এখনও `platform/`-এর বাইরে: `filemanager/` (`common`, `unzip`, `fix_permissions`), `api/` (`mailbox_storage`), `edge_gateway/` (`dispatcher`, `terminal_ws`), `installer/service.rs`, আর `try_chown`-এর caller। C9 শুধু কয়েকটা ঢাকে; বাকিগুলোর কোনো task নেই, C11-এর আগে একটা দরকার।
- F1: Windows `Paths`, `FileAccess` ও local `AccountManager`-এর unit test পাস। `filemanager/e2e_local.rs` ignored, হাতে চালাতে হয়; suite সেটা প্রমাণ করে না। F1-এর `Depends`-এর C1 ও C4-এর CI এখন সবুজ।
- F2 চলছে, আর **H1 পাস করার আগেই** শুরু হয়েছে, আর H1-এর সিদ্ধান্ত এখনও হয়নি ([spikes/H1.md](spikes/H1.md))। H1 pool-এর নকশা বদলাতে পারে। হয়েছে: `edge_gateway/php_cgi_pool.rs`, spec §9-এর default, প্রতি site-এ pool, TCP-তে `FastCgiEndpoint`। Crash, recycle, idle, queue ও 503-এর test একটা stand-in process দিয়ে পাস। আসল php-cgi দিয়ে শুধু একবার হাতে দেখা: `mysite.test` render হয়, worker মেরে দিলে পরের request-এ নতুন worker আসে। আসল php-cgi-র 500 request recycle চালানো হয়নি।
- F2-এ বাকি: gateway হঠাৎ মরলে php-cgi থেকে যায় (Job Object দরকার); "alert" এখন শুধু log; এই PC-র php-cgi TS build, spec চায় NTS; `DRUST_API_TOKEN` PHP-তে না যাওয়া শুধু unit test-এ প্রমাণিত, আসল process-এ নয়।
- Windows local-এ gateway চলে: `DPANEL_PROFILE=local`, `DPANEL_PANEL_ROOT`, `DRUST_HTTP_BIND`, `DRUST_HTTPS_BIND`, `DRUST_API_TOKEN` লাগে, আর `PATH`-এ PHP। SQLite panel-এর site PHP CLI দিয়ে পড়ে, কারণ Rust-এ SQLite crate নেই; প্রতি reload-এ কয়েকটা PHP process চলে (মাপা reload 0.7-0.9 সেকেন্ড)। Port 80-তে এখনও `php artisan serve`।
- বাকি system path অন্য task-এর: `/etc/systemd` C2/C3, `/etc/passwd` ও `/etc/shadow` C4, sandbox-এর ভেতরের path ও `runuser` C5, `/etc/letsencrypt` এখন শুধু `platform/acme/`-এ (I5), `/etc/fail2ban` J7, ClamAV J10, `/etc/php` ও PHP-FPM pool F2/J4।
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
