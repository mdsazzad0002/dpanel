# serverpanel_sso (Roundcube)

This plugin enables one-time-token SSO from ServerPanel to Roundcube without sending the mailbox password through the browser.

## Install

1. Copy `serverpanel_sso/` into your Roundcube `plugins/` directory.
2. In Roundcube `config/config.inc.php`:
   - Add `serverpanel_sso` to `$config['plugins']`.
   - Set:
     - `$config['serverpanel_sso_panel_url'] = 'https://cp.yourdomain.com:2083/sso/webmail/consume';`
     - `$config['serverpanel_sso_secret'] = '...same as WEBMAIL_SSO_SECRET...';`

## ServerPanel

- Set `WEBMAIL_SSO_SECRET` (and optionally `WEBMAIL_SSO_REQUIRE_LOCAL=true`) in ServerPanel `.env`.
- Use the existing `GET /emails/{id}/login` route; when `WEBMAIL_SSO_SECRET` is set, ServerPanel redirects to Roundcube with `sso_token`.
