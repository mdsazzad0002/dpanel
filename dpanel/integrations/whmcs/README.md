# WHMCS backend integration

```env
WHMCS_API_CLIENT_ID=whmcs-production
WHMCS_API_SECRET=<output-of-openssl-rand-hex-32>
WHMCS_ALLOWED_IPS=203.0.113.10/32
WHMCS_ALLOWED_DOMAINS=billing.example.com
```

Run `php artisan migrate --force` and `php artisan config:cache`. Copy `DPanelApiClient.php` into the private WHMCS server module; never expose its secret to JavaScript.

Or configure and synchronize `.env` in one command:

```bash
sudo bash integrations/whmcs/configure.sh --client-id whmcs-production --allowed-ip 203.0.113.10 --allowed-domain billing.example.com
```

The command preserves an existing secret. Add `--rotate-secret` only for an intentional credential rotation.
You can also set an explicit generated secret with `--secret YOUR_64_CHARACTER_SECRET`.

All API calls are signed POST requests. Canonical input:

```text
HTTP_METHOD\nREQUEST_PATH\nWHMCS_DOMAIN\nUNIX_TIMESTAMP\nNONCE\nSHA256_HEX_OF_RAW_BODY
```

Construct the client with the dPanel server URL, client ID, secret and the exact allowlisted WHMCS hostname. The hostname is sent as `X-WHMCS-Domain` and is covered by the signature. IP verification uses the actual backend connection address; neither browser `Origin` nor `Referer` is trusted.

Use WHMCS service ID as `external_id` and dPanel package slug as `plan_slug`. SSO URLs expire in 60 seconds and work once. Termination revokes access while preserving hosted data for recovery.
