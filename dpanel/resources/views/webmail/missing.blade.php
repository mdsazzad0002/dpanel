<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Roundcube Not Configured</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #0f172a; background: #f1f5f9; }
        .box { max-width: 760px; margin: 0 auto; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; background: #ffffff; }
        .muted { color: #475569; font-size: 14px; }
        .code { margin-top: 8px; padding: 10px; border-radius: 8px; background: #0f172a; color: #f8fafc; font-family: Consolas, "Courier New", monospace; font-size: 13px; overflow-x: auto; }
        .warn { color: #b91c1c; font-size: 14px; }
        .links a { color: #1d4ed8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="margin-top:0;">Roundcube URL Not Ready</h2>
        <p class="warn">Roundcube is not being served on the panel webmail path right now.</p>
        <p class="muted">Set a real webmail endpoint (or use `auto`) in your `ServerPanel/.env`:</p>
        <div class="code">WEBMAIL_URL={{ ($configuredUrl !== '' && strtolower($configuredUrl) !== 'auto') ? $configuredUrl : $defaultUrl }}</div>

        <p class="muted" style="margin-top:14px;">After updating `.env`, clear config cache (if used) and reload Apache/Nginx.</p>
        <div class="code">php artisan config:clear</div>

        <div class="links" style="margin-top:16px;">
            <a href="{{ $panelUrl }}">Go to Email Accounts in Panel</a>
        </div>
    </div>
</body>
</html>
