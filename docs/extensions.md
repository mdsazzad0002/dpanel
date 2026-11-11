# Extensions

An extension adds a feature to dPanel from outside the panel: an uptime report,
a billing bridge, a custom dashboard. It runs on its own server or host name,
and it can only use the parts of the [dPanel API](api.md) that an admin
approves when installing it. The design is in
[plans/extension-spec.md](../plans/extension-spec.md).

## Contents

- [How it works](#how-it-works)
- [Installing an extension](#installing-an-extension)
- [Managing extensions](#managing-extensions)
- [Writing an extension](#writing-an-extension)
- [The manifest](#the-manifest)
- [Webhooks](#webhooks)
- [The admin page](#the-admin-page)
- [Limits of version 1](#limits-of-version-1)

## How it works

- The extension's code never runs inside the panel. No PHP, Vue or shell code
  from an extension is loaded by dPanel, and nothing runs as root.
- On install, the panel makes a **service account** for the extension and one
  **API token** on it, with exactly the scopes the admin ticked. The service
  account cannot sign in to the panel and is not listed with the users.
- The extension calls `https://<panel>/api/v1` with that token, like any other
  API client. Every change it makes is in the activity log as `api.request`.
- The panel can send the extension **events** (such as a new website) as signed
  webhooks.
- The extension can have one **admin page**, shown inside the panel in a
  sandboxed iframe loaded from the extension's own address.

## Installing an extension

Only admins can do this.

1. Open **System → Extensions**.
2. Paste the extension's manifest (`dpanel-extension.json`) or choose the file,
   then press **Check manifest**. Problems are listed by field.
3. Read what it asks for. Tick only the scopes you agree to; the extension can
   never get a scope its manifest does not ask for, nor one you do not have.
   An event whose scope you leave unticked is not sent.
4. Press **Install**. The panel shows the extension's **API token** and
   **webhook secret** once. Give both to the extension (usually in its own
   settings or environment), then close the notice.

## Managing extensions

Each installed extension on **System → Extensions** shows its granted scopes,
when its token was last used, its events and the last webhook result.

| Action | What it does |
| --- | --- |
| **Open** | Shows the extension's admin page, if it has one |
| **Disable** | Its token stops working (`403 This extension is disabled.`), no events are sent and its page is closed. Nothing is deleted |
| **Enable** | The same token works again |
| **Send test event** | Sends a signed `ping` now and shows the answer |
| **New token and secret** | Makes new credentials, shown once. The old token stops working at once |
| **Uninstall** | Deletes the extension, its token and its service account. Websites, records and other things it made through the API stay |

To **update**, check and install the new version's manifest the same way. The
panel sees the same `id`, shows the installed version and marks scopes that are
new. The token stays; its scopes become the ones you tick now.

## Writing an extension

An extension is any web service you host yourself. It needs:

- a manifest,
- a place to keep the token and the secret the admin gives it,
- optionally an HTTPS endpoint for webhooks and an HTTPS page for the panel.

Its addresses must use `https://` (plain `http://` only on `127.0.0.1` or
`localhost`), and must not be on the panel's own host name: serve it from
another host name, such as `uptime.example.com` for a panel on
`panel.example.com`.

## The manifest

```json
{
    "manifest_version": 1,
    "id": "acme.uptime",
    "name": "Uptime report",
    "version": "1.0.0",
    "description": "Checks every website once a minute.",
    "author": "Acme",
    "homepage": "https://acme.example/uptime",
    "panel": ">=1.0 <2.0",
    "scopes": ["websites:read"],
    "ui": {
        "admin_page": { "title": "Uptime", "url": "https://uptime.example.com/dpanel" }
    },
    "webhook": { "url": "https://uptime.example.com/dpanel/events" },
    "events": ["website.created", "website.deleted"]
}
```

| Key | Required | Meaning |
| --- | --- | --- |
| `manifest_version` | yes | Always `1` |
| `id` | yes | 3-64 lowercase letters, digits, dots and dashes; the same for every version |
| `name` | yes | Up to 80 characters |
| `version` | yes | Semantic version, such as `1.2.0` |
| `description`, `author` | no | Shown to the admin |
| `homepage` | no | `https://` address |
| `panel` | yes | dPanel versions it works with: space-separated comparators (`>=`, `>`, `<=`, `<`, `=`), all of which must hold, such as `>=1.0 <2.0` |
| `scopes` | no | API scopes it asks for: `websites`, `dns`, `databases`, `mail`, each `:read` or `:write` |
| `ui.admin_page` | no | `title` (up to 40 characters) and `url` of its admin page |
| `webhook.url` | if `events` | Where events are sent |
| `events` | no | Events it wants; each needs the read (or write) scope of its area |

Unknown keys are refused, so a manifest for a later format is never half read.

Events in version 1:

| Event | Scope | `data` |
| --- | --- | --- |
| `website.created` | `websites:read` | `id`, `domain`, `type`, `parent_id`, `owner_id` |
| `website.deleted` | `websites:read` | the same |
| `ping` | - | empty; sent only from **Send test event** |

## Webhooks

Each event is a `POST` with a JSON body:

```json
{
    "extension": "acme.uptime",
    "id": "5f0c...",
    "event": "website.created",
    "occurred_at": "2026-11-12T10:00:00+00:00",
    "data": { "id": "...", "domain": "shop.example.com", "type": "primary", "parent_id": null, "owner_id": 7 }
}
```

and these headers:

| Header | Value |
| --- | --- |
| `X-Dpanel-Event` | The event name |
| `X-Dpanel-Delivery` | The `id`, the same on every retry; use it to skip duplicates |
| `X-Dpanel-Timestamp` | Unix time of this send |
| `X-Dpanel-Signature` | `sha256=` and the hex HMAC-SHA256 of `<timestamp>.<raw body>` with the webhook secret |

Check every request before trusting it:

```php
$timestamp = (int) $_SERVER['HTTP_X_DPANEL_TIMESTAMP'];
$body = file_get_contents('php://input');
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
if (! hash_equals($expected, $_SERVER['HTTP_X_DPANEL_SIGNATURE'] ?? '') || abs(time() - $timestamp) > 300) {
    http_response_code(401);
    exit;
}
```

Answer with any `2xx` status within 10 seconds. Anything else is a failure: the
panel tries up to three times (after 10 and 60 seconds) and shows the last
error on the extension's card. Redirects are not followed. Events are queued, so
the panel's queue worker must be running.

## The admin page

The panel shows the page in an iframe with
`sandbox="allow-scripts allow-forms allow-popups"` and no referrer. Without
`allow-same-origin` the page runs in an opaque origin: it cannot read the
panel's cookies, storage or page, and it cannot use its own cookies either. The
panel page also sends `Content-Security-Policy: frame-src <your origin>`, so it
frames nothing else.

Your page must allow the panel to frame it (for example
`Content-Security-Policy: frame-ancestors https://panel.example.com`).

The panel adds `dpanel_context` to the page's URL: who is looking, signed with
the webhook secret, valid for 5 minutes.

```text
<base64url JSON>.<base64url HMAC-SHA256 of the first part>
```

The JSON has `extension`, `user` (`id`, `name`, `role`), `locale`, `iat` and
`exp`. Check the signature and `exp` on your server before you show anything,
then render the page there; carry state in your own links or forms, since the
page has no cookies.

## Limits of version 1

- dPanel does not run extension code. Host the extension yourself; running it
  on the server under drust is planned (see the spec).
- One UI slot (the admin page) and two events.
- No marketplace or signed packages: the admin decides whom to trust, by
  reading the manifest.
- Extensions are for admins only; resellers and users cannot install them.
