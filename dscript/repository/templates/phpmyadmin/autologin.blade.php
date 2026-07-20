<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>phpMyAdmin Auto Login</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #0f172a; }
        .box { max-width: 640px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; background: #f8fafc; }
        .muted { color: #475569; font-size: 14px; }
        .err { color: #b91c1c; font-size: 13px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="margin-top:0;">Opening phpMyAdmin...</h2>
        <p class="muted">Database: <strong>{{ $database }}</strong></p>
        <p class="muted" id="status">Creating secure login session...</p>
        <p id="error" class="err" style="display:none;"></p>
        <form id="pma-fallback-form" method="post" action="{{ $helperUrl }}" style="display:none;">
            <input type="hidden" name="username" value="{{ $username }}">
            <input type="hidden" name="password" value="{{ $password }}">
            <input type="hidden" name="host" value="{{ $host }}">
            <input type="hidden" name="db" value="{{ $database }}">
            <input type="hidden" name="pma_username" value="{{ $username }}">
            <input type="hidden" name="pma_password" value="{{ $password }}">
            <input type="hidden" name="pma_host" value="{{ $host }}">
        </form>
    </div>
    <script>
        const payload = {
            username: @json($username),
            password: @json($password),
            host: @json($host),
            db: @json($database),
            pma_username: @json($username),
            pma_password: @json($password),
            pma_host: @json($host),
        };

        const statusEl = document.getElementById('status');
        const errorEl = document.getElementById('error');
        const helperUrl = @json($helperUrl);

        const fallbackPost = () => {
            statusEl.textContent = 'Trying fallback login flow...';
            document.getElementById('pma-fallback-form').submit();
        };

        fetch(helperUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(payload),
        })
        .then((res) => res.json())
        .then((data) => {
            if (data && data.success && data.redirect) {
                statusEl.textContent = 'Session created. Redirecting to phpMyAdmin...';
                window.location.href = data.redirect;
                return;
            }

            throw new Error((data && data.message) ? data.message : 'Session creation failed');
        })
        .catch((err) => {
            errorEl.style.display = 'block';
            errorEl.textContent = err.message || 'Auto login failed.';
            fallbackPost();
        });
    </script>
</body>
</html>
