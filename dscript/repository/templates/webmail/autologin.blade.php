<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Webmail Login</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #0f172a; }
        .box { max-width: 620px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 18px; background: #f8fafc; }
        .muted { color: #475569; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="margin-top:0;">Opening Webmail...</h2>
        <p class="muted">Mailbox: <strong>{{ $email }}</strong></p>
        <p class="muted">If auto-login does not start, click Continue.</p>
        <form id="webmail-login-form" method="post" action="{{ $targetUrl }}">
            <input type="hidden" name="_task" value="login">
            <input type="hidden" name="_action" value="login">
            <input type="hidden" name="_user" value="{{ $email }}">
            <input type="hidden" name="_pass" value="{{ $password }}">
            <button type="submit">Continue</button>
        </form>
    </div>
    <script>
        document.getElementById('webmail-login-form').submit();
    </script>
</body>
</html>
