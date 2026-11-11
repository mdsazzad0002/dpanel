# Extension System Spec (U7)

dPanel-এর extension (plugin) ব্যবস্থার নকশা। Backlog-এর U7: "Manifest, permission, sandbox নকশা আলাদা spec-এ"। Public API (U1, [platform-spec.md](platform-spec.md) §8.1)-এর ওপর দাঁড়ানো। User doc: [docs/extensions.md](../docs/extensions.md)।

## 1. লক্ষ্য ও মূল নিয়ম

অন্য panel-এ কী আছে:

| Panel | Extension কীভাবে চলে | সমস্যা |
| --- | --- | --- |
| CyberPanel `pluginHolder` | Django app, panel-এর process-এই load হয় | Plugin পুরো panel ও root-এর ক্ষমতা পায় |
| aaPanel `panelPlugin` | Python module, panel-এর process-এ, root হিসেবে | একই; store থেকে নামানো code root-এ চলে |
| PhyrePanel `Modules` | Laravel module, panel-এর code-এর অংশ | Panel-এর PHP process-এর সব ক্ষমতা পায় |
| OpenPanel extensions | Panel-এর ভেতরে template ও Python | Panel-এর process-এ |

চারটাতেই extension panel-এর process-এ চলে, তাই একটা খারাপ বা ভাঙা extension মানে পুরো server। dPanel-এর নিয়ম উল্টো:

1. **Extension-এর code কখনো panel-এর PHP process-এ, panel-এর Vue bundle-এ বা root হিসেবে চলে না।** Panel extension-এর কোনো PHP, JS বা shell code load করে না।
2. **Extension panel-এর সঙ্গে কথা বলে শুধু `/api/v1` দিয়ে**, নিজের scoped token-এ (U1-এর `api_tokens`)।
3. **Token admin install-এর সময় যা অনুমোদন করে, তার বেশি কখনো পায় না**: manifest যা চায় ∩ admin নিজে যা পারে ∩ admin যা টিক দেয়।
4. **Token কোনো মানুষের account-এ বাঁধা নয়**: প্রতিটা extension-এর নিজের service account।
5. **UI panel-এর origin-এ চলে না**: sandboxed iframe, extension-এর নিজের origin থেকে।

## 2. Manifest (version 1)

File-এর নাম `dpanel-extension.json`, JSON object, সর্বোচ্চ 64 KB। Format strict: অজানা key error, যাতে পরের version-এর manifest অর্ধেক বোঝা না হয়। নতুন key মানে নতুন `manifest_version`।

```json
{
    "manifest_version": 1,
    "id": "acme.uptime",
    "name": "Uptime report",
    "version": "1.0.0",
    "description": "...",
    "author": "Acme",
    "homepage": "https://acme.example/uptime",
    "panel": ">=1.0 <2.0",
    "scopes": ["websites:read"],
    "ui": { "admin_page": { "title": "Uptime", "url": "https://uptime.example.com/dpanel" } },
    "webhook": { "url": "https://uptime.example.com/dpanel/events" },
    "events": ["website.created", "website.deleted"]
}
```

| Key | নিয়ম |
| --- | --- |
| `manifest_version` | `1` |
| `id` | 3-64, `[a-z0-9]` আর মাঝে `.` বা `-`; সব version-এ একই; install-এ unique |
| `name` | 1-80 অক্ষর, control character নয় |
| `version` | Semver `X.Y.Z(-pre)` |
| `description`, `author`, `homepage` | ঐচ্ছিক; `homepage` শুধু `https://` |
| `panel` | Space দিয়ে আলাদা comparator (`>=`, `>`, `<=`, `<`, `=`, চিহ্ন না থাকলে `=`), সর্বোচ্চ 4টা, সবগুলো মানতে হয়; `config('app.version')` না মিললে install হয় না। `^`, `~`, `||` নেই |
| `scopes` | U1-এর `ApiScopes::all()` থেকে, দুবার নয় |
| `ui` | Version 1-এ শুধু `admin_page` {`title` ≤ 40, `url`} |
| `webhook.url` | `events` থাকলে লাগবে |
| `events` | §6-এর তালিকা থেকে; প্রতিটা event-এর area-র `:read` বা `:write` scope manifest-এ থাকতে হবে |
| `runtime` | Version 1-এ refuse, স্পষ্ট message-সহ (§5.1) |

Extension-এর URL (`ui.admin_page.url`, `webhook.url`): `https://`; plain `http://` শুধু `127.0.0.1`, `::1`, `localhost`-এ; `user:pass@` নয়; `#fragment` নয়; panel-এর নিজের host (`APP_URL`-এর host বা request-এর host, port যা-ই হোক) নয়, কারণ সেটা panel-এর same-site হতো।

Error সব একসঙ্গে, field-এর নাম দিয়ে শুরু (`scopes: "servers:root" is not an API scope...`), `422`-এ `errors.manifest`-এ।

## 3. Permission model

### 3.1 Service account

Install করলে panel একটা `users` row বানায়: `is_service_account = true`, `role = admin`, নাম `Extension: <name>`, email `<id>@extensions.dpanel.invalid`, random password। `is_service_account` fillable নয়।

`User`-এর global scope `HumanUserScope` সব Eloquent query থেকে এদের বাদ দেয়। ফলে:

- Session guard ও password reset user খোঁজে Eloquent দিয়ে, তাই service account কখনো login করতে পারে না (password জানা থাকলেও)।
- User list, গণনা, "সব admin-কে notification", API-র `owner_id` lookup-এ আসে না।
- শুধু `ApiToken::user()` ও `Extension::serviceUser()` scope-টা নাম ধরে বাদ দেয়।

Role `admin` কারণ শুধু admin install করে, আর U1-এর `visibleTo` admin-কে সব দেখায়; কী করতে পারবে সেটা scope ঠিক করে, role নয়।

### 3.2 Token

একটা `api_tokens` row, owner service account, নাম `extension:<id>`, plain token একবার দেখানো, hash রাখা (U1-এর মতো)। Scope:

```
granted = approved, যেখানে approved ⊆ manifest.scopes ∩ ApiScopes::allowedFor(installing admin)
```

এর বাইরে কিছু চাইলে `422`, কিছুই বানানো হয় না। Update-এ (একই `id`-এর নতুন manifest) admin আবার টিক দেয়, একই নিয়মে; token থাকে, scope বদলায়। নতুন চাওয়া scope review-এ "new" চিহ্নিত।

`AuthenticateApiToken`: owner service account হলে তার extension `enabled` হতে হবে, নয়তো `403 This extension is disabled.`। Disable token মোছে না, তাই enable করলে একই token আবার চলে। "New token and secret" পুরোনো token সঙ্গে সঙ্গে মুছে নতুন দেয়।

U1-এর সব নিয়ম খাটে: প্রতি token-এ মিনিটে 120 request, `GET` ছাড়া সফল request activity log-এ `api.request`।

### 3.3 Admin-এর কাজ

সব route `role:admin`। Install, update, enable/disable, credential বদল, uninstall activity log-এ `extension.*`।

## 4. Install, update, disable, uninstall

| কাজ | কী হয় |
| --- | --- |
| Review | Manifest parse ও যাচাই; কী দেওয়া যায় (`grantable`) আর install করা version (থাকলে) |
| Install | এক transaction-এ service account, `extensions` row (secret encrypted), token; token ও webhook secret একবার দেখানো |
| Update | একই `id`; manifest, version, URL, event ও token-এর scope বদলায়; credential একই থাকে |
| Disable | `enabled = false`: token `403`, event যায় না, page `404` |
| Enable | আগের token আবার চলে |
| New credentials | নতুন token ও secret, পুরোনো token মুছে |
| Uninstall | Token, `extensions` row, service account মোছে। Extension API দিয়ে যা বানিয়েছে (website, record) থাকে, কারণ সেগুলো যার জন্য বানানো তার |

Event subscription install-এর সময়ই ছাঁটা হয়: যে event-এর scope দেওয়া হয়নি, সেটা `extensions.events`-এ থাকে না।

## 5. Sandbox

### 5.1 Code কোথায় চলে

**Version 1:** extension-এর code dPanel চালায় না। Admin নিজে host করে: অন্য server-এ, বা এই server-এর একটা আলাদা website হিসেবে (নিজের site user, নিজের host name)। Panel শুধু তার URL জানে। তাই U7-এ drust বদলায়নি।

**পরে (backlog-এ আলাদা task):** manifest-এ `runtime` (যেমন `{"command": "bin/server", "port": "auto"}`) আর signed package। drust-এর `AccountManager` দিয়ে প্রতিটা extension-এর নিজের system user (`dpx-<id>`), `ServiceManager` দিয়ে নিজের service (Linux-এ systemd unit, sandbox option `NoNewPrivileges`, `ProtectSystem=strict`, `PrivateTmp`, শুধু নিজের folder-এ লেখা), `ResourceLimiter`-এ limit, loopback-এর একটা port; token environment-এ যায়, file-এ নয়। Gateway `ext-<id>.<panel host>`-এর মতো আলাদা host name-এ সেটাকে serve করে, যাতে origin আলাদা থাকে। সবকিছু platform layer-এর পেছনে, Windows ও macOS-এ আগে `501`।

### 5.2 UI

Version 1-এর একমাত্র UI slot `admin_page`: System → Extensions → Open।

- `<iframe sandbox="allow-scripts allow-forms allow-popups" referrerpolicy="no-referrer" allow="">`। কখনো `allow-same-origin`, `allow-top-navigation` বা `allow-modals` নয়। `allow-same-origin` না থাকায় page opaque origin-এ চলে: panel-এর cookie, storage বা DOM ছুঁতে পারে না, panel-কে navigate করতে পারে না, camera ইত্যাদি পায় না।
- URL panel-এর host-এ হতে পারে না (§2), তাই sandbox ছাড়াও origin আলাদা।
- Frame-ধারী page-এর response-এ `Content-Security-Policy: frame-src <extension origin>; frame-ancestors 'self'`। Extensions page থেকে link সাধারণ `<a>` (Inertia visit নয়), তাই full page load হয় আর header খাটে। (`child-src` দেওয়া হয়নি, কারণ সেটা panel-এর worker-ও আটকাতো।)
- Panel-এর Vue bundle-এ extension-এর কোনো code নেই; component শুধু server-এর দেওয়া attribute bind করে।
- Opaque origin-এ extension নিজের cookie-ও পায় না, তাই কে দেখছে জানাতে panel URL-এ `dpanel_context` যোগ করে: `base64url(JSON).base64url(HMAC-SHA256(প্রথম অংশ, webhook secret))`, JSON-এ `extension`, `user` {`id`, `name`, `role`} (email নয়), `locale`, `iat`, `exp` (5 মিনিট)। Extension নিজের server-এ যাচাই করে page render করে।
- `postMessage` bridge নেই। Version 1-এ panel iframe থেকে কোনো message শোনে না।

পরের slot (website-এর tab, dashboard widget) একই iframe নিয়মে, বা declarative JSON UI (panel নিজের component দিয়ে আঁকে, extension শুধু data দেয়)। Extension-এর Vue code panel-এ load করা কখনো নয়।

## 6. Event ও webhook

| Event | লাগে | `data` |
| --- | --- | --- |
| `website.created` | `websites:read` বা `:write` | `id`, `domain`, `type`, `parent_id`, `owner_id` |
| `website.deleted` | একই | একই |
| `ping` | - | `{}`; শুধু admin-এর "Send test event" থেকে |

Website model-এর Eloquent `created`/`deleted` event থেকে (`ExtensionEvents::listen`, `AppServiceProvider`-এ)। Query দিয়ে bulk delete Eloquent event দেয় না, তাই এখন mailbox ও database-এর event নেই (§9)।

পাঠানো: প্রতিটা চালু, subscribe করা, scope পাওয়া extension-এর জন্য আলাদা queued job (`DeliverExtensionEvent`, `afterCommit`)। পাঠানোর সময় আবার যাচাই (মাঝে disable হলে যায় না)। `POST` JSON `{extension, id, event, occurred_at, data}`; header `X-Dpanel-Event`, `X-Dpanel-Delivery` (retry-তেও একই), `X-Dpanel-Timestamp`, `X-Dpanel-Signature: sha256=<hex HMAC-SHA256("<timestamp>.<body>", secret)>`। Timeout 10 সেকেন্ড, redirect follow হয় না, `2xx` ছাড়া সব ব্যর্থ; 3 বার চেষ্টা (10 ও 60 সেকেন্ড পরে)। ব্যর্থতা কখনো throw হয় না, তাই sync queue-তেও website বানানো ভাঙে না। শেষ ফল `extensions.last_delivery_*`-এ।

Payload-এ id ও নাম, কখনো password, key বা token নয়।

## 7. Trust ও উৎস

Version 1: কোনো store বা marketplace নেই, package-ও নেই, শুধু manifest। Admin manifest paste বা upload করে। Panel URL থেকে manifest নামায় না (SSRF এড়াতে)। Trust-এর সিদ্ধান্ত admin-এর: review screen-এ id, author, homepage, scope, URL, event সব দেখায়।

পরে (`runtime` আসার সঙ্গে): package (`.tar.gz`) আর তার manifest-এ Ed25519 signature; panel-এ trusted publisher key-এর তালিকা (admin যোগ করে); unsigned package শুধু admin-এর স্পষ্ট "unsigned চালাও" দিয়ে; update-এ একই key লাগবে।

## 8. Threat ও প্রতিকার

| Threat | প্রতিকার |
| --- | --- |
| Extension root বা panel-এর ক্ষমতা নিতে চায় | Code panel-এ বা root-এ চলে না; শুধু `/api/v1` |
| Manifest বেশি scope চায় | Admin টিক দেয়; manifest ∩ admin-এর নিজের scope-এর বাইরে `422`; test আছে |
| Update দিয়ে চুপিচুপি scope বাড়ানো | Update-এ আবার অনুমোদন; নতুন scope চিহ্নিত; manifest-এ না থাকা scope `422` |
| Token চুরি | Hash রাখা, একবার দেখানো; disable সঙ্গে সঙ্গে `403`; "New token and secret" |
| Service account দিয়ে login | `HumanUserScope`: Eloquent-এ খুঁজে পাওয়া যায় না; password জানা থাকলেও login ব্যর্থ, test আছে |
| Service account অন্য user-এর মতো list বা notification-এ | Global scope সব query থেকে বাদ দেয় |
| Extension-এর page panel-এর cookie বা DOM পড়ে (XSS-এর মতো) | `allow-same-origin` ছাড়া sandbox, আলাদা host, CSP `frame-src`; Vue code load হয় না |
| Page panel-কে phishing page-এ navigate করে | `allow-top-navigation` নেই |
| Context চুরি করে অন্য কেউ সেজে extension-এ ঢোকা | HMAC, 5 মিনিট মেয়াদ, email নেই; extension-এর নিজের আর কিছু নেই |
| Webhook জাল করা | HMAC signature, timestamp দিয়ে replay 5 মিনিটে সীমিত (extension যাচাই করে), delivery id দিয়ে duplicate বাদ |
| Webhook দিয়ে SSRF | URL admin-অনুমোদিত, https বা শুধু loopback http, panel host নয়, redirect follow হয় না, body নির্দিষ্ট JSON, উত্তর কাউকে দেখানো হয় না শুধু status। বাকি ঝুঁকি: loopback-এর অন্য service-এ signed JSON POST |
| ধীর বা মৃত webhook panel আটকায় | Queue-তে, 10 সেকেন্ড timeout, ব্যর্থতা throw হয় না |
| Event দিয়ে data ফাঁস | Scope না থাকলে event যায় না; payload-এ শুধু id ও নাম |
| বড় বা ভাঙা manifest | 64 KB, depth 16, strict key, সব error একসঙ্গে |
| Extension uninstall হলেও token চলে | Uninstall token ও service account মোছে, test আছে |

## 9. Version 1-এ যা ইচ্ছা করে নেই

- dPanel-এ extension-এর code চালানো (`runtime`, drust-এর service, signed package) — §5.1, §7।
- Admin page ছাড়া UI slot; declarative UI; `postMessage` bridge।
- `website.*` ছাড়া event (mailbox, database, DNS, SSL, backup), কারণ সেগুলো এখন query দিয়ে মোছে বা Eloquent event দেয় না।
- Reseller বা user-এর install করা extension; প্রতি-customer অনুমতি।
- Store, rating, স্বয়ংক্রিয় update check।
- URL থেকে manifest নামানো।
- Webhook delivery-র history (শুধু শেষ ফল)।

## 10. Data model ও route

`extensions`: `identifier` (unique), `name`, `version`, `description`, `author`, `homepage`, `manifest` (JSON), `granted_scopes` (JSON), `events` (JSON, scope দিয়ে ছাঁটা), `ui_title`, `ui_url`, `webhook_url`, `webhook_secret` (app key দিয়ে encrypted), `enabled`, `service_user_id` (users, cascade), `installed_by` (users, null on delete), `last_delivery_at`, `last_delivery_status`, `last_delivery_error`। `users`-এ `is_service_account` (default `false`)।

Panel route (session, `role:admin`, prefix `system/extensions`): `GET /` (page), `POST /review`, `POST /` (install বা update), `PUT /{id}/state` `{enabled}`, `POST /{id}/credentials`, `POST /{id}/ping`, `DELETE /{id}`, `GET /{id}/page`। `/api/v1`-এ নতুন route নেই; extension U1-এর endpoint-ই ব্যবহার করে।

Code: `app/Services/Extensions/` (`ExtensionManifest`, `ExtensionManager`, `ExtensionEvents`, `ExtensionSigner`), `app/Jobs/DeliverExtensionEvent.php`, `app/Models/Extension.php`, `app/Models/Scopes/HumanUserScope.php`, `app/Http/Controllers/Settings/ExtensionController.php`, `resources/js/Pages/System/Extensions/`। Test: `tests/Feature/Extensions/`।
