# dPanel API

dPanel has a REST API for your own scripts, a CLI or a billing system: list,
create and delete websites, DNS records, databases and mailboxes.

## Contents

- [Quick start](#quick-start)
- [Tokens and scopes](#tokens-and-scopes)
- [Endpoints](#endpoints)
- [Errors and limits](#errors-and-limits)
- [Configuration](#configuration)

## Quick start

1. Sign in to the panel and open **Profile menu → API Tokens**.
2. Create a token: give it a name, the scopes it needs and an expiry.
3. Copy the token. It starts with `dp-api-` and is shown only once.
4. Call the API:

   ```bash
   curl -H "Authorization: Bearer dp-api-..." https://panel.example.com/api/v1/websites
   ```

The API lives at `https://<your-panel-domain>/api/v1`. Use the panel over HTTPS
so the token is never sent in clear text.

The full reference is an OpenAPI 3 document at
`https://<your-panel-domain>/api/v1/openapi` (no token needed). Open it in
any OpenAPI viewer, or generate a client from it. The file in the repository is
[`dpanel/resources/api/openapi.yaml`](../dpanel/resources/api/openapi.yaml).

## Tokens and scopes

Every user may make tokens, for themselves. A token acts as the user who made
it and can never do more than that user can in the panel:

- An admin's token sees everything.
- A reseller's token sees their own customers' websites, zones, databases and
  mailboxes.
- A customer's token sees only their own.

| Scope | Allows |
| --- | --- |
| `websites:read`, `websites:write` | List and show websites; create and delete them |
| `dns:read`, `dns:write` | List zones and records; add, change and delete records |
| `databases:read`, `databases:write` | List databases; create and remove them |
| `mail:read`, `mail:write` | List mailboxes; create and delete them, change passwords |

A `write` scope also allows reading the same area. The panel only offers the
scopes your role allows (for example, a role without **Manage Databases** gets
no database scopes). If your role later loses a permission, tokens that have
that scope get `403` for it; `GET /api/v1/me` shows which scopes still work.

Only a SHA-256 hash of the token is stored. The page shows when each token was
last used and from which address. **Revoke** stops a token at once. An expired
token stops working by itself.

While an account is suspended (by an admin, a reseller or WHMCS), all its
tokens get `403`. They work again after it is unsuspended.

Every change made through the API (any request other than `GET`) is written
to the activity log as `api.request`, with the token's name, the method, the
path and the status.

## Endpoints

| Method and path | Scope | What it does |
| --- | --- | --- |
| `GET /me` | none | The token's owner and scopes |
| `GET /websites` | `websites:read` | List websites |
| `GET /websites/{id}` | `websites:read` | Show a website |
| `POST /websites` | `websites:write` | Create a website or subdomain |
| `DELETE /websites/{id}` | `websites:write` | Delete a website |
| `GET /dns/zones` | `dns:read` | List zones |
| `GET /dns/zones/{zone}/records` | `dns:read` | List a zone's records |
| `POST /dns/zones/{zone}/records` | `dns:write` | Add a record |
| `PUT /dns/zones/{zone}/records/{id}` | `dns:write` | Change a record |
| `DELETE /dns/zones/{zone}/records/{id}` | `dns:write` | Delete a record |
| `GET /databases` | `databases:read` | List databases |
| `POST /databases` | `databases:write` | Create a database and its user |
| `DELETE /databases/{id}` | `databases:write` | Remove a database from the panel |
| `GET /mailboxes` | `mail:read` | List mailboxes |
| `POST /mailboxes` | `mail:write` | Create a mailbox |
| `PUT /mailboxes/{id}/password` | `mail:write` | Change a mailbox's password |
| `DELETE /mailboxes/{id}` | `mail:write` | Delete a mailbox and its mail |

Each action does the same as the panel page, with the same checks and package
limits. A few things to know:

- **Websites**: `POST` needs `domain` and `php_version`. For a subdomain, send
  `"type": "sub"` and the parent's `parent_id`. Admins and resellers may set
  `owner_id`; a customer always owns the sites they create. Deleting a main
  site archives its files to the trash first.
- **DNS**: `{zone}` is the zone's domain (`example.com`) or its id. Record
  names are `@`, `www` or a full name. `PUT` changes only the fields you send.
  The SOA record is not listed and cannot be changed here.
- **Databases**: `POST` takes the website's domain, a name and a user. Both get
  the site's prefix (`shop_example_com_app`). The password is generated when
  left out and is only returned in the `POST` response. `DELETE` removes the
  panel's record, as the Databases page does; the database and its data stay
  on the server.
- **Mailboxes**: only on the domain of one of your websites.

Example: add a TXT record.

```bash
curl -X POST https://panel.example.com/api/v1/dns/zones/example.com/records \
  -H "Authorization: Bearer dp-api-..." -H "Content-Type: application/json" \
  -d '{"type": "TXT", "name": "@", "content": "v=spf1 mx -all", "ttl": 3600}'
```

## Errors and limits

Every response is JSON, errors included:

```json
{"message": "The ttl field must be at least 60.", "errors": {"ttl": ["The ttl field must be at least 60."]}}
```

| Status | Meaning |
| --- | --- |
| `401` | No token, or an invalid, expired or revoked one |
| `403` | The token lacks the scope, the owner may no longer use that area, or the account is suspended |
| `404` | Not found, or not yours |
| `422` | Invalid input, or a package limit was reached (`errors` says which field) |
| `429` | Too many requests; wait `Retry-After` seconds |

Each token may make 120 requests a minute, counted across all endpoints.

## Configuration

In `dpanel/.env`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `DPANEL_API_RATE_LIMIT` | `120` | Requests a minute for each token |
| `DPANEL_API_MAX_TOKEN_DAYS` | `3650` | Longest lifetime a new token may have |

Run `php artisan config:cache` after changing them.

The AI Gateway's OpenAI-compatible endpoints (`/api/v1/models`,
`/api/v1/chat/completions`, ...) share the `/api/v1` prefix but use their own
`sk-ag-` keys; see [AI Gateway](ai-gateway.md).
