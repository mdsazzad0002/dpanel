# S1 Spike: WAF engine

**সিদ্ধান্ত: libmodsecurity 3, FFI দিয়ে, কিন্তু gateway-র process-এ নয়, আলাদা worker process-এ।** Coraza নয়।

মাপা হয়েছে 2026-11-09, একটা Linux PC-তে, OWASP CRS v4.30.0 paranoia level 1 দিয়ে, দুই engine-এ একই rule ও একই request। Harness ও আবার চালানোর নিয়ম [waf/](waf/)-এ।

## কেন

| | libmodsecurity 3 | Coraza (RE2) | Coraza (Go regexp) |
| --- | --- | --- | --- |
| সাধারণ GET, p50 | 2.3 ms | 2.7 ms | 2.6 ms |
| 2 KB form POST, p50 | 4.0 ms | 5.0 ms | 22.5 ms |
| 8 thread-এ request/s | **1930** | 520 | 591 |
| Memory (rule load-এর পর পুরো process) | **53 MB** | 213-241 MB | 84-90 MB |
| Attack আটকায় (SQLi, XSS) | হ্যাঁ, 403 | হ্যাঁ, 403 | হ্যাঁ, 403 |

- **একটা request-এ দুটো প্রায় সমান।** তাই Coraza-র "memory-safe" সুবিধা ছাড়া তার পক্ষে কিছু নেই।
- **Core বাড়লে libmodsecurity বাড়ে, Coraza বাড়ে না।** libmodsecurity 1 থেকে 8 thread-এ 5.9 গুণ। Coraza RE2-এ 4 thread-এর পর থেমে যায় (WebAssembly-তে চলা RE2), Go regexp-এ বাড়ে, কিন্তু body-তে খুব ধীর।
- **Coraza sidecar হলে আরও ধীর।** Unix socket-এ HTTP sidecar হিসেবে সাধারণ GET-এর p50 6.2 ms, in-process-এর দ্বিগুণের বেশি। Socket নিজে দায়ী নয়: rule ছাড়া একই sidecar-এ এক round trip 0.14-0.22 ms। বাড়তিটা Go-র HTTP server ও header map-এর।
- **libmodsecurity-র ঝুঁকি C++ code request-এর পথে।** Crash বা memory leak হলে পুরো gateway পড়বে। আলাদা worker process-এ রাখলে সেই ঝুঁকি worker-এ থাকে, আর দাম মাপা hop-এর মতো, 0.2 ms-এর কম। তাই সিদ্ধান্তে এই দুটো মেলানো।

একটা WordPress page-এর PHP সাধারণত 50-300 ms নেয়, তাই 2-4 ms WAF মোটামুটি 1-5% বাড়তি। Static file, edge cache-এর hit আর ACME-কে WAF-এ পাঠানোর দরকার নেই, সেগুলো আগের মতো দ্রুত থাকবে।

## সংখ্যা

একটা request-এর সময়, µs, একটা thread, প্রতি request 300 বার (warm-up-এর পর)। এক সময়ে পরপর চালানো:

| Request | libmodsecurity p50 / p99 | Coraza RE2 p50 / p99 | Coraza Go regexp p50 / p99 | Coraza RE2 sidecar p50 / p99 |
| --- | --- | --- | --- | --- |
| `get_page` | 2281 / 2827 | 2732 / 6929 | 2619 / 8050 | 6155 / 9201 |
| `get_cookies` | 2166 / 3429 | 2465 / 5560 | 2593 / 6761 | 5722 / 8885 |
| `post_form` (2 KB) | 4020 / 5671 | 4992 / 13307 | 22526 / 46425 | 13004 / 18478 |
| `attack_sqli` → 403 | 2228 / 2737 | 2214 / 4444 | 2500 / 6869 | 5605 / 8165 |
| `attack_xss` → 403 | 2606 / 3901 | 2863 / 5018 | 3507 / 9462 | 6501 / 9401 |
| `post_json` (3.5 KB, 180টা field)* | 40392 / 54152 | 62185 / 92371 | 71328 / 129199 | মাপা হয়নি |

\* আলাদা run-এ। JSON-এর প্রতিটা field একটা `ARGS`, আর CRS প্রতিটায় rule চালায়, তাই সব engine-এ ব্যয়বহুল।

উপরের পাঁচটার মিশ্রণে (JSON ছাড়া) request/s:

| Thread | libmodsecurity | Coraza RE2 | Coraza Go regexp | Coraza RE2 sidecar |
| --- | --- | --- | --- | --- |
| 1 | 328 | 314 | 145 | 145 |
| 4 | 1451 | 576 | 455 | 533 |
| 8 | 1930 | 520 | 591 | 515 |

Rule load: libmodsecurity 852টা rule 116 ms, +38 MB। Coraza 186 ms, +39 MB। Coraza RE2 574 ms, +82 MB।

libmodsecurity-র সময় কোথায় যায় (`perf`, JSON request): `malloc`, `free` ও `std::string` copy মিলে এক-তৃতীয়াংশের বেশি; প্রথম চোদ্দটা symbol-এ PCRE2-র একটাই, 1.5%। মানে ধীরতা libmodsecurity 3-এর নকশার, Ubuntu-র build-এর নয়, আর নিজে build করে খুব একটা বদলাবে না।

## এই ফল কতটা ধরা যায়

- **Machine:** AMD Ryzen 5 3400G, 4 core, 8 thread, 20 GB RAM, Ubuntu 26.04। মাপার সময় desktop চালু ছিল (Firefox, VS Code; load average 2.5-3.5), তাই p99 noisy। দুই engine একই সময়ে পরপর মাপা।
- **Version:** libmodsecurity 3.0.14 (Ubuntu package), Coraza v3.8.1, coraza-wasilibs v0.3.0, Go 1.27.3, CRS v4.30.0।
- **শুধু request:** phase 1 ও 2। Response body দেখা বন্ধ (`SecResponseBodyAccess Off`), audit log বন্ধ। Response দেখলে দুটোরই খরচ বাড়বে।
- **Gateway নয়:** harness সরাসরি engine ডাকে। Worker process ও তার protocol বানানো বা মাপা হয়নি; hop-এর সংখ্যা Go-র HTTP sidecar থেকে, Rust worker-এ কম হওয়ার কথা।
- **CRS-এর নিজের test (go-ftw) চালানো হয়নি।** দুটো attack আর পাঁচটা ভালো request দেখা হয়েছে, false positive-এর হিসাব নেই। S2-তে চালাতে হবে।
- **শুধু Linux।** libmodsecurity Windows-এ ও macOS-এ কেমন build হয় দেখা হয়নি (macOS-এ Homebrew-তে আছে)। এটা Coraza-র একমাত্র বড় সুবিধা: Go তিন OS-এ সহজে build হয়। J ও K-তে দেখতে হবে; না হলে সেখানে `waf` capability বন্ধ থাকবে।

## S2-এর জন্য

- `drust` নিজেই `waf-worker` mode-এ কয়েকটা process চালাবে (core-এর অর্ধেক দিয়ে শুরু)। Gateway request-এর মাথা আর body-র প্রথম অংশ Unix socket-এ পাঠায়, উত্তর শুধু "ছাড়ো" বা status। Worker মরলে gateway নতুন চালায়।
- শুধু dynamic request: PHP বা proxy-তে যাওয়া। Static file, edge cache hit, ACME বাদ।
- Default **detection-only** (`SecRuleEngine DetectionOnly`), domain প্রতি on/off আর blocking।
- Body: 128 KB পর্যন্ত। JSON-এর খরচ (40 ms) কমাতে বড় JSON-এ সীমা বা rule exclusion লাগবে; মেপে ঠিক করতে হবে।
- Worker সময়ে উত্তর না দিলে (প্রথমে 200 ms) বা মরলে request ছেড়ে দেওয়া (fail-open) ও log, কারণ WAF-এর জন্য site বন্ধ হওয়া চলবে না। Admin চাইলে fail-closed।
- Match-এর log site-এর নিজের log-এ, যাতে S4-এর Logs page-এ দেখা যায়।
- Linux package: `libmodsecurity3` (Debian/Ubuntu-তে আছে)। RHEL-এর জন্য উৎস দেখতে হবে।
