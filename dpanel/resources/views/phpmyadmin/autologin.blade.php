<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Opening phpMyAdmin</title>
</head>
<body>
    <p>Opening phpMyAdmin for <strong>{{ $database }}</strong>…</p>
    <form id="phpmyadmin-signon" method="post" action="{{ $helperUrl }}">
        <input type="hidden" name="pma_username" value="{{ $username }}">
        <input type="hidden" name="pma_password" value="{{ $password }}">
        <input type="hidden" name="pma_host" value="{{ $host }}">
        <input type="hidden" name="db" value="{{ $database }}">
        @if (! empty($allowRoot))
            <input type="hidden" name="pma_allow_root" value="1">
        @endif
        <noscript><button type="submit">Continue to phpMyAdmin</button></noscript>
    </form>
    <script>document.getElementById('phpmyadmin-signon').submit();</script>
</body>
</html>
