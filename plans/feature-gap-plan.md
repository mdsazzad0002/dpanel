# dPanel Feature Gap Plan

অন্য hosting panel-এর সঙ্গে dPanel মিলিয়ে কী কী নেই বা অসম্পূর্ণ, আর কোন ক্রমে বানানো উচিত।

তারিখ: 2026-11-07। Windows/macOS-এ চালানোর plan আলাদা: [windows-macos-native-plan.md](windows-macos-native-plan.md)।

## সংক্ষেপে

dPanel-এর feature তালিকা বড়, কিন্তু সবচেয়ে বড় ফাঁকটা নতুন feature-এ নয়। **Hosting বিক্রির মূল নিয়ম গুলো এখনো enforce হয় না:** suspend করলে website চলতেই থাকে, আর storage, bandwidth ও CPU/RAM limit কার্যত নেই। তাই প্রথম কাজ এগুলো ঠিক করা, তারপর WAF ও firewall, তারপর বাকি feature।

| Phase | বিষয় | আনুমানিক সময় (১ জন dev) |
| --- | --- | --- |
| 1 | Account enforcement: suspend, disk, bandwidth, CPU/RAM, mail limit | 5-7 সপ্তাহ |
| 2 | নিরাপত্তা: WAF, firewall UI, log viewer | 5-7 সপ্তাহ |
| 3 | SSL, DNS, Mail-এর ফাঁক | 6-8 সপ্তাহ |
| 4 | WordPress toolkit ও incremental backup | 6-8 সপ্তাহ |
| 5 | Platform: public API, extension, ভাষা, white-label | 8-10 সপ্তাহ |
| 6 | Multi-server ও অন্যান্য | পরে ঠিক হবে |

সময়গুলো মোটা দাগের অনুমান, মাপা হিসাব নয়। প্রতিটা phase শুরুর আগে আবার হিসাব করতে হবে।

## কীভাবে যাচাই করা হয়েছে

প্রতিটা panel একই গভীরতায় দেখা সম্ভব হয়নি। তাই নিচের তুলনা পড়ার সময় এই সীমা মাথায় রাখুন।

| Panel | কী দেখা হয়েছে | ভরসা |
| --- | --- | --- |
| CyberPanel | Source tree, `urls.py` (website, firewall, emailPremium, IncBackups) | বেশি |
| HestiaCP | README ও `bin/` command list | বেশি |
| aaPanel | File tree, `panelDnsapi.py` | মাঝারি: বেশিরভাগ file-এর নাম থেকে |
| Virtualmin | File tree | মাঝারি: file-এর নাম থেকে |
| jabali-panel | README, ADR শিরোনাম, agent package | মাঝারি: ADR-এর নমুনা থেকে, পুরোটা নয় |
| OpenPanel | Module ও docs tree | মাঝারি: তালিকা আংশিক |
| Laranode, PhyrePanel | File tree | মাঝারি: এদের module কম |
| Plesk | Official docs-এর search result (WP Toolkit, ModSecurity) | কম: closed-source, docs সরাসরি খোলা যায়নি |
| DirectAdmin | Official docs-এর search result | কম: closed-source, feature page পুরোনো হতে পারে |
| CloudPanel | Official docs-এর search result | কম: public repo-তে source নেই |

**যাচাই করা যায়নি:** CyberPanel `highAvailability`-এর `urls.py` থেকে কোনো route পাওয়া যায়নি। Plesk-এর extension ও mail feature এই research-এ আসেনি।

dPanel-এর দিকে যা "নেই" লেখা হয়েছে, তার ভিত্তি code search ও code পড়া। কোনো feature চালিয়ে পরীক্ষা করা হয়নি। প্রতিটা কাজ শুরুর আগে একটা failing test দিয়ে gap-টা নিশ্চিত করুন।

> **License সতর্কতা:** jabali-panel AGPL-3.0। CyberPanel, HestiaCP ও Virtualmin-এর license-ও যাচাই না করে ধরে নেওয়া যাবে না। dPanel-এর license source-available, তাই এসব repo থেকে **শুধু idea নিন, code copy করবেন না**। কোনো library যোগ করার আগে তার license দেখুন।

## এখনই যা শক্তিশালী

এগুলো নতুন করে বানানোর দরকার নেই:

- Rust edge gateway: HTTP/3, edge cache, compression, per-domain bandwidth গণনা
- Migration: cPanel, DirectAdmin, Plesk ও CyberPanel থেকে import
- Backup storage: Google Drive, S3, S3-compatible, SFTP
- Mail: Rspamd, DKIM, delivery diagnostics, mail health
- AI Gateway, MCP access, Site Push, Git deploy, Docker
- 2FA, Fail2ban UI, ClamAV scan, sandboxed web terminal, WHMCS

## Phase 1: Account enforcement (সবার আগে)

এই ফাঁকগুলোর কারণে একজন customer তার plan-এর বাইরে যেতে পারে, অথবা suspend হওয়ার পরও সেবা পেতে পারে।

### 1.1 Suspend সত্যিই কার্যকর করা

**এখনকার অবস্থা:**

- `is_suspended` শুধু login ([LoginRequest.php:57](../dpanel/app/Http/Requests/Auth/LoginRequest.php#L57)) আর MCP token-এ check হয়।
- Admin panel থেকে suspend করলে session revoke হয় না ([UserManagementController.php](../dpanel/app/Http/Controllers/UserManagementController.php)), কিন্তু WHMCS path করে। তাই আগে থেকে logged-in user panel ব্যবহার করে যেতে পারার কথা।
- WHMCS `terminate` শুধু suspended mark করে ([WhmcsController.php:77](../dpanel/app/Http/Controllers/Api/WhmcsController.php#L77)), কিছু মোছে না।
- `drust/src`-এ suspend নিয়ে কোনো code নেই, তাই website, mail, FTP ও cron চলতে থাকে।

**যা করতে হবে:**

- একটা `AccountSuspensionService` বানান, যেটা panel ও WHMCS দুই পথই ব্যবহার করবে।
- Suspend-এ session revoke, website-এ "suspended" page, আর mail, FTP, cron, queue worker ও SSH বন্ধ করুন।
- Request-এর সময় check করার middleware যোগ করুন।
- Edge gateway config-এ site-এর `suspended` state যোগ করুন।
- `terminate`-এর জন্য grace period রেখে আলাদা delete flow বানান। মুছে ফেলা ফেরানো যায় না, তাই আগে backup নিতে হবে।

**রেফারেন্স:** Hestia-র `suspend-*` command family, CyberPanel-এর `siteState`।

### 1.2 Disk quota

**এখনকার অবস্থা:** `max_storage_mb` শুধু একটা mailbox-এর size-এর সঙ্গে তুলনা হয় ([ResourceQuotaService.php:30](../dpanel/app/Services/ResourceQuotaService.php#L30))। Website বা database-এর জায়গার কোনো limit নেই।

**যা করতে হবে:**

- ext4/XFS-এ filesystem quota সেট করুন (প্রতিটা site-এর Linux account আগে থেকেই আলাদা, তাই সহজ)।
- Quota চালু করা না গেলে নিয়মিত `du` scan ও soft-limit fallback রাখুন।
- Package-এ limit বদলালে quota নিজে থেকে update হবে।
- Usage dashboard, আর 80%/100%-এ notification দিন।

**ঝুঁকি:** Quota চালু করতে filesystem remount লাগতে পারে। Installer ও পুরোনো server-এর migration আলাদা করে পরীক্ষা করতে হবে।

**রেফারেন্স:** Virtualmin `quotas`, `quotacheck`।

### 1.3 Bandwidth limit

**এখনকার অবস্থা:** Gateway গুনে রাখে ([bandwidth.rs](../drust/src/edge_gateway/bandwidth.rs)) আর panel report দেখায়, কিন্তু `max_bandwidth_gb` কোথাও enforce হয় না।

**যা করতে হবে:** মাসিক হিসাবের সঙ্গে limit মিলিয়ে দেখা, পেরোলে notification, আর admin-এর পছন্দমতো throttle বা "limit exceeded" page। মাস শেষে reset।

**রেফারেন্স:** Virtualmin `bwreset`, `bwgraph`; CyberPanel monthly reset।

### 1.4 CPU/RAM limit

**এখনকার অবস্থা:** Limit শুধু web terminal-এ আছে। একটা site পুরো server-এর CPU/RAM খেয়ে ফেলতে পারে।

**যা করতে হবে:** প্রতি account-এ systemd slice (`CPUQuota`, `MemoryMax`, `TasksMax`), PHP-FPM pool-এর `pm.max_children` plan অনুযায়ী, আর `max_cpu_percent`, `max_memory_mb` package field।

**রেফারেন্স:** jabali ADR-0068 (per-user cgroup slice)।

### 1.5 Outbound mail limit

**এখনকার অবস্থা:** `MailOutboundGate` শুধু PTR/IPv6 সমস্যা সামলায়। প্রতি mailbox বা domain-এ sending limit নেই। একটা hack হওয়া site পুরো server-এর IP blacklist করাতে পারে।

**যা করতে হবে:** Rspamd ratelimit module ব্যবহার করে প্রতি ঘণ্টা ও দিনের limit (Rspamd আগে থেকেই আছে)। Plan-ভিত্তিক মান, limit ছাড়ালে alert। Panel-এ mail queue দেখা ও মোছা।

**রেফারেন্স:** CyberPanel `emailPremium` (domain ও per-email limit, `mailQueue`), Virtualmin `ratelimit`, jabali ADR-0111।

## Phase 2: নিরাপত্তা

### 2.1 Web Application Firewall (WAF)

**এখনকার অবস্থা:** নেই। Plesk, CyberPanel, jabali ও aaPanel-এ আছে।

**আসল চ্যালেঞ্জ:** dPanel-এর web server নিজের Rust gateway, তাই Apache/Nginx-এর ModSecurity module এখানে বসে না।

| পথ | সুবিধা | অসুবিধা |
| --- | --- | --- |
| **libmodsecurity 3 FFI (প্রস্তাবিত)** | OWASP CRS সরাসরি চলে | C dependency, request path-এ ঝুঁকি |
| Coraza sidecar | Memory-safe, CRS চলে | আলাদা Go service, প্রতি request-এ একটা বাড়তি hop |
| নিজের Rust rule engine | সবচেয়ে দ্রুত | CRS-এর মান ধরতে অনেক কাজ |

দুই সপ্তাহের একটা spike করে latency মেপে তারপর চূড়ান্ত করুন। Per-domain on/off দিন, আর default রাখুন **detection-only**, কারণ Plesk-এর docs অনুযায়ী OWASP CRS বেশ কড়া, production-এ tuning লাগে।

### 2.2 Firewall UI

**এখনকার অবস্থা:** Panel-এ শুধু status আর command guide আছে। `web.php`-এ firewall-এর দুটোই `GET` route, rule add/delete করার route নেই। Rule বদলাতে SSH-এ `dpanel firewall` চালাতে হয়।

**যা করতে হবে:** Panel থেকে port/IP rule add, delete ও list। Active SSH port lock-out থেকে রক্ষা (নিয়ম প্রয়োগের পর confirm না করলে rollback)। RHEL পরিবারে firewalld support।

**রেফারেন্স:** CyberPanel `firewall`, Hestia `firewall-*`, Laranode `Ufw*Action`।

### 2.3 Per-site log viewer

**এখনকার অবস্থা:** `Pages/Websites`-এ access/error log দেখার UI পাওয়া যায়নি, আর gateway-তে per-site access log-এর code পাওয়া যায়নি। Log পড়ার tool শুধু MCP-র মধ্যে আছে।

**যা করতে হবে:** Gateway-তে per-site access log (rotation সহ), panel-এ PHP error ও access log দেখা, আর ঐচ্ছিক traffic report।

**রেফারেন্স:** Hestia `web-domain-stats`, aaPanel `log_analysis`।

### 2.4 ছোট hardening

- Password-protected directory: `HtaccessResolver` directive চেনে, কিন্তু gateway-তে `WWW-Authenticate` code পাওয়া যায়নি। (Hestia `httpauth`, aaPanel `site_dir_auth`)
- Upload folder-এ PHP execution বন্ধ। (aaPanel `php_execute_deny`)
- Hotlink protection।
- Cloudflare-only traffic option। (CloudPanel)
- পরে: CrowdSec ও file integrity monitoring। (jabali ADR-0087, 0089)

## Phase 3: SSL, DNS, Mail

| কাজ | এখনকার অবস্থা | রেফারেন্স |
| --- | --- | --- |
| Wildcard SSL (DNS-01) | শুধু HTTP-01 আছে | Hestia; aaPanel `panelDnsapi` (Cloudflare, GoDaddy, Namecheap ইত্যাদি) |
| Custom certificate upload | পাওয়া যায়নি | jabali ADR-0141 |
| DNSSEC | DB field আছে, কিন্তু PowerDNS-এ `gmysql-dnssec=no` | Hestia `change-dns-domain-dnssec` |
| DNS cluster / secondary | পাওয়া যায়নি | Hestia `sync-dns-cluster`, DirectAdmin |
| Autoresponder | পাওয়া যায়নি (Sieve শুধু spam-এর জন্য) | Hestia `mail-account-autoreply`, DirectAdmin |
| Catch-all | পাওয়া যায়নি | Hestia `mail-domain-catchall`, DirectAdmin |
| Forward-only address | Forwarding আছে mailbox-এর সঙ্গে; mailbox ছাড়া forwarder পাওয়া যায়নি | Hestia `mail-account-fwd-only` |
| SMTP relay | Server-এর `relayhost` শুধু পড়া হয়; panel থেকে set করার ব্যবস্থা পাওয়া যায়নি | Hestia `mail-domain-smtp-relay` |
| Mailing list | নেই | DirectAdmin |

**ক্রম:** Autoresponder, catch-all ও forward-only আগে, কারণ এগুলো ছোট কাজ এবং customer রোজ চায়। তারপর wildcard SSL ও custom certificate, তারপর DNSSEC, শেষে DNS cluster। Mailing list সবার শেষে।

## Phase 4: WordPress ও Backup

### 4.1 WordPress Toolkit

**এখনকার অবস্থা:** WordPress install আর SSO login আছে। `wp-cli` ব্যবহার হয় না, plugin/theme management বা staging নেই।

**যা করতে হবে:**

1. `wp-cli` যোগ করুন, আর সব WordPress site এক জায়গায় দেখান।
2. Plugin, theme ও core update (একটা বা অনেকগুলো একসঙ্গে)।
3. Staging: clone তৈরি ও production-এ deploy। `CloneWebsiteJob` ও Site Push আগে থেকেই আছে, এগুলোর ওপর বানান।
4. Security check ও hardening।
5. Safe update: clone-এ update করে HTTP status ও PHP error দেখে তবেই production-এ লাগানো।

**রেফারেন্স:** CyberPanel (`CreateStagingNow`, `DeploytoProduction`, `UpdatePlugins`), Plesk WP Toolkit। Plesk-এ Smart Updates paid, dPanel-এ free দিলে এটা বড় পার্থক্য হবে।

### 4.2 Backup

**এখনকার অবস্থা:** Storage-এর বিকল্প ভালো, কিন্তু backup পুরো archive হিসেবে হয়। `docs/backups.md`-এ incremental বা encryption-এর উল্লেখ নেই।

**যা করতে হবে:** Restic দিয়ে incremental ও encrypted backup, restore point থেকে শুধু একটা file, database বা mailbox ফেরানো, আর restore-test report।

**রেফারেন্স:** CyberPanel `IncBackups` (restic), jabali ADR-0078, 0121।

## Phase 5: Platform

| কাজ | এখনকার অবস্থা | রেফারেন্স |
| --- | --- | --- |
| Public REST API | শুধু WHMCS, Site Push, AI Gateway, MCP আছে; সাধারণ API নেই | CyberPanel `api`, aaPanel `panelApi`, OpenPanel `modules/api` |
| Extension system | নেই | CyberPanel `pluginHolder`, aaPanel `panelPlugin`, PhyrePanel `Modules`, OpenPanel extensions |
| বহু ভাষা | `vue-i18n` বা `lang/` নেই | CyberPanel `locale`, Hestia (Crowdin), aaPanel `languages` |
| White-label | নেই; reseller-দের জন্য দরকার | - |
| Multiple IP | পাওয়া যায়নি | Hestia `sys-ip`, jabali ADR-0049 |
| Update center | CLI-তে আছে, panel-এ পূর্ণ UI নেই বলে মনে হচ্ছে; যাচাই দরকার | jabali ADR-0118 |
| App catalog বাড়ানো | WordPress, Laravel, Joomla, Drupal, CodeIgniter ও WHMCS আছে | Hestia-র 22টা WebApp installer (Nextcloud, PrestaShop, OpenCart, MediaWiki ইত্যাদি) |
| Vhost/app template | নেই | CloudPanel vhost templates |

**ক্রম:** Public API আগে, কারণ extension, WHMCS-ছাড়া billing ও CLI সবই এর ওপর দাঁড়াবে। প্রথমে একটা scoped token ব্যবস্থা ও versioned `/api/v1` দরকার।

ভাষার কাজ দেরিতে শুরু করলে পরে বেশি খরচ হয়, কারণ Vue page-এর সংখ্যা বাড়ছে। নতুন page-এ এখন থেকেই translation key ব্যবহার করা ভালো।

## Phase 6: পরে ভাবার মতো

- Multi-server management (aaPanel `bt-client`)। বড় architecture পরিবর্তন, আলাদা design লাগবে।
- Hestia ও aaPanel থেকে migration।
- প্রতি user-এ container isolation (OpenPanel-এর পথ)। dPanel-এর site-account মডেল থেকে বড় পরিবর্তন, এখন নয়।
- Ruby runtime।

## যা বানানোর দরকার নেই

- **Imunify, CloudLinux/CageFS:** Paid third-party পণ্য। ClamAV ও বিদ্যমান sandbox নিয়েই এগোন, `free forever` মডেলের সঙ্গেও এটাই মেলে।
- **OpenLiteSpeed/Apache/Nginx বিকল্প:** নিজের gateway-ই dPanel-এর বৈশিষ্ট্য। বাড়তি web server মানে maintenance দ্বিগুণ।
- **Varnish:** Edge cache আগে থেকেই আছে।
- **Email marketing:** Hosting panel-এর মূল কাজ নয়, আর এতে spam-এর ঝুঁকি বাড়ে।

## কাজের নিয়ম

- প্রতিটা feature আলাদা component ও service-এ বানান। `Manage.vue` এর মতো বড় page আর বাড়াবেন না।
- প্রতিটা enforcement-এর জন্য test লিখুন: limit-এর নিচে, ঠিক limit-এ, আর limit-এর ওপরে।
- Quota, cgroup, firewall ও DNSSEC Ubuntu, Debian ও RHEL পরিবারে আলাদা করে পরীক্ষা করুন।
- Existing server-এর জন্য migration রাখুন। Enforcement চালু হলে পুরোনো customer হঠাৎ বন্ধ হয়ে যাওয়া চলবে না, তাই প্রথমে report-only mode দিন।

## সিদ্ধান্ত দরকার

1. Bandwidth limit পেরোলে default কী হবে: শুধু notification, throttle, নাকি site বন্ধ?
2. WordPress-এর safe update কি সবার জন্য free থাকবে? (প্রস্তাব: হ্যাঁ)
3. `terminate` করলে data কতদিন রেখে তারপর মুছবে?

## সূত্র

- [CyberPanel](https://github.com/usmannasir/cyberpanel), [HestiaCP](https://github.com/hestiacp/hestiacp), [aaPanel](https://github.com/aaPanel/aaPanel), [Virtualmin](https://github.com/virtualmin/virtualmin-gpl)
- [jabali-panel](https://github.com/shukiv/jabali-panel), [OpenPanel](https://github.com/stefanpejcic/OpenPanel), [Laranode](https://github.com/crivion/laranode), [PhyrePanel](https://github.com/PhyreApps/PhyrePanel)
- [Plesk WP Toolkit](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/wp-toolkit.73391/), [Plesk ModSecurity](https://docs.plesk.com/en-US/obsidian/administrator-guide/server-administration/web-application-firewall-modsecurity.73383/)
- [DirectAdmin features](https://www.directadmin.com/features_list.php), [DirectAdmin DNS](https://docs.directadmin.com/other-hosting-services/dns/index.html#how-the-multiserver-dns-clustering-works)
- [CloudPanel CLI](https://www.cloudpanel.io/docs/v2/cloudpanel-cli/root-user-commands/), [CloudPanel Varnish](https://www.cloudpanel.io/blog/cloudpanel-version-2-1-with-varnish-cache/)
