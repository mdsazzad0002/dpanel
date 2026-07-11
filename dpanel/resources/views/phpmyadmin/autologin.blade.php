<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>phpMyAdmin Auto Login</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: rgba(15, 23, 42, 0.84);
            --border: rgba(148, 163, 184, 0.18);
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --danger: #fb7185;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, 0.16), transparent 26%),
                radial-gradient(circle at bottom right, rgba(14, 165, 233, 0.16), transparent 24%),
                linear-gradient(180deg, #020617 0%, #0f172a 55%, #111827 100%);
        }

        .box {
            width: min(640px, 100%);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 34px;
            background: var(--panel);
            box-shadow: 0 24px 80px rgba(2, 6, 23, 0.45);
            backdrop-filter: blur(16px);
        }

        .eyebrow {
            margin: 0 0 12px;
            font-size: 12px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--accent);
        }

        h1 {
            margin: 0;
            font-size: clamp(30px, 4vw, 46px);
            line-height: 1.05;
        }

        .muted {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
        }

        .status {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 26px;
            padding: 18px 20px;
            border-radius: 20px;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.12);
        }

        .spinner {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid rgba(148, 163, 184, 0.28);
            border-top-color: var(--accent);
            animation: spin 0.9s linear infinite;
            flex: 0 0 auto;
        }

        .status strong,
        .status span {
            display: block;
        }

        .status strong {
            font-size: 15px;
        }

        .status span {
            color: var(--muted);
            font-size: 14px;
            margin-top: 3px;
        }

        .err {
            display: none;
            margin-top: 16px;
            border-radius: 16px;
            padding: 14px 16px;
            background: rgba(127, 29, 29, 0.25);
            border: 1px solid rgba(251, 113, 133, 0.3);
            color: #fecdd3;
            font-size: 14px;
        }

        .footer {
            margin-top: 18px;
            font-size: 12px;
            color: var(--muted);
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="box">
        <p class="eyebrow">phpMyAdmin auto login</p>
        <h1>Verifying access</h1>
        <p class="muted">Database: <strong>{{ $database }}</strong></p>
        <div class="status" id="statusBox">
            <div class="spinner" id="spinner"></div>
            <div>
                <strong id="status">Preparing secure phpMyAdmin session...</strong>
                <span>We are issuing a signon token and redirecting you automatically.</span>
            </div>
        </div>
        <p id="error" class="err"></p>
        <p class="footer">This page only exists to complete the phpMyAdmin auto-login handshake.</p>
        @if($debugEnabled ?? false)
            <p class="footer">Debug mode is enabled.</p>
        @endif
        <form id="issueForm" method="post" action="{{ $issueUrl }}" style="display:none;">
            @csrf
            <input type="hidden" name="action" value="issue">
        </form>
        <noscript>
            <p class="muted" style="margin-top: 16px;">
                JavaScript is disabled. Click the button below to continue.
            </p>
            <button type="submit" form="issueForm" class="btn" style="border:0;border-radius:999px;padding:12px 18px;background:#38bdf8;color:#00111f;font-weight:600;cursor:pointer;">
                Continue to phpMyAdmin
            </button>
        </noscript>
    </div>
    <script>
        const statusEl = document.getElementById('status');
        const errorEl = document.getElementById('error');
        const spinner = document.getElementById('spinner');
        const issueForm = document.getElementById('issueForm');

        const go = () => {
            statusEl.textContent = 'Creating session and redirecting to phpMyAdmin...';
            issueForm.submit();
        };

        go();
    </script>
</body>
</html>
