<?php

declare(strict_types=1);

// @serverpanel-starter
$host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost')));
$host = preg_replace('/:\\d+$/', '', $host) ?: 'localhost';
$scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        ? 'https'
        : 'http';
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__)) ?: __DIR__;
$serverSoftware = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'drust-edge-gateway');
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <link rel="icon" href="./assets/favicon.ico" sizes="any">
    <link rel="shortcut icon" href="./assets/favicon.ico">
    <link rel="stylesheet" href="./assets/bootstrap-icons/bootstrap-icons.min.css">
    <title><?= $escape($host) ?> is ready</title>
    <style>
        :root { color-scheme:dark; --ink:#f8fafc; --muted:#94a3b8; --paper:#03060f; --line:rgba(255,255,255,.10); --green:#34d399; --cyan:#22d3ee; --panel:rgba(255,255,255,.045); }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; color:var(--ink); background:radial-gradient(circle at 12% 10%,rgba(16,185,129,.18),transparent 30%),radial-gradient(circle at 88% 88%,rgba(14,165,233,.14),transparent 28%),linear-gradient(180deg,#040814 0%,#02050a 100%); font-family:Georgia,"Times New Roman",serif; overflow-x:hidden; }
        body::before { content:""; position:fixed; inset:0; pointer-events:none; opacity:.4; background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px); background-size:72px 72px; }
        .shell { position:relative; isolation:isolate; width:min(980px,calc(100% - 36px)); margin:30px auto; padding:28px 30px 24px; border:1px solid var(--line); border-radius:30px; background:linear-gradient(145deg,rgba(255,255,255,.075),rgba(255,255,255,.025)); box-shadow:0 40px 120px rgba(0,0,0,.58),inset 0 1px rgba(255,255,255,.08); backdrop-filter:blur(24px); overflow:hidden; }
        .shell::before { content:""; position:absolute; z-index:-1; width:320px; height:320px; right:-130px; top:-170px; border-radius:50%; background:rgba(52,211,153,.13); filter:blur(10px); }
        .shell::after { content:""; position:absolute; z-index:-1; width:260px; height:260px; left:-150px; bottom:-180px; border-radius:50%; background:rgba(34,211,238,.09); filter:blur(8px); }
        .topline { display:flex; justify-content:space-between; gap:20px; align-items:center; border-bottom:1px solid var(--line); padding:0 2px 17px; font:700 10px/1.2 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.16em; text-transform:uppercase; }
        .signal { display:flex; align-items:center; gap:9px; color:var(--green); }
        .signal::before { content:""; width:10px; height:10px; border-radius:50%; background:var(--green); box-shadow:0 0 0 5px rgba(52,211,153,.14),0 0 22px rgba(52,211,153,.55); }
        .hero { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(235px,.5fr); gap:clamp(28px,5vw,58px); padding:46px 4px 38px; align-items:end; }
        .eyebrow { margin:0 0 15px; color:var(--green); font:700 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.16em; text-transform:uppercase; }
        h1 { margin:0; max-width:680px; font-size:clamp(42px,6.3vw,76px); font-weight:500; line-height:.9; letter-spacing:-.05em; overflow-wrap:anywhere; }
        h1 em { color:var(--green); font-weight:500; text-shadow:0 0 42px rgba(52,211,153,.16); }
        .intro { margin:18px 0 0; max-width:530px; color:var(--muted); font-size:clamp(15px,1.5vw,18px); line-height:1.55; }
        .stamp { position:relative; padding:23px; border:1px solid rgba(52,211,153,.18); border-radius:20px; background:linear-gradient(145deg,rgba(16,185,129,.09),rgba(255,255,255,.025)); box-shadow:0 22px 55px rgba(0,0,0,.28),inset 0 1px rgba(255,255,255,.06); backdrop-filter:blur(18px); }
        .stamp::after { content:"LIVE"; position:absolute; right:18px; top:-16px; padding:8px 13px; color:#020617; border-radius:999px; background:linear-gradient(90deg,var(--green),var(--cyan)); box-shadow:0 10px 32px rgba(16,185,129,.22); font:800 11px/1 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.15em; }
        .stamp strong { display:block; font-size:22px; font-weight:500; line-height:1.15; }
        .stamp p { margin:10px 0 0; color:var(--muted); font-size:14px; line-height:1.45; }
        .facts { display:grid; grid-template-columns:repeat(4,1fr); border:1px solid var(--line); border-radius:18px; background:rgba(2,6,23,.38); overflow:hidden; box-shadow:inset 0 1px rgba(255,255,255,.025); }
        .fact { min-width:0; padding:16px 17px 18px; }
        .fact + .fact { border-left:1px solid var(--line); }
        .fact span { display:block; margin-bottom:9px; color:var(--muted); font:700 10px/1 ui-monospace,SFMono-Regular,Consolas,monospace; letter-spacing:.13em; text-transform:uppercase; }
        .fact b { display:block; font-size:13px; font-weight:600; overflow-wrap:anywhere; }
        footer { display:flex; justify-content:space-between; gap:20px; padding:17px 3px 0; color:var(--muted); font:10px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace; }
        @media (max-width:760px) { body{display:block}.shell{width:calc(100% - 24px);margin:12px;padding:22px 18px 18px;border-radius:24px}.hero{grid-template-columns:1fr;padding:38px 2px 32px}.stamp{margin-top:4px}.facts{grid-template-columns:1fr 1fr}.fact:nth-child(3){border-left:0}.fact:nth-child(n+3){border-top:1px solid var(--line)}footer{display:block}.topline>span:last-child{display:none} }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topline"><span class="signal"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Service online</span><span>Powered by dPanel + Rust</span></header>
        <section class="hero">
            <div>
                <p class="eyebrow">Deployment confirmed</p>
                <h1><?= $escape($host) ?><br><em>is ready.</em></h1>
                <p class="intro">The domain is connected and PHP is responding. Replace this starter file when your application is ready to launch.</p>
            </div>
            <aside class="stamp"><strong>Everything works.</strong><p>HTTP routing, dynamic PHP execution, and the website document root are correctly configured.</p></aside>
        </section>
        <section class="facts">
            <div class="fact"><span>Protocol</span><b><?= $escape(strtoupper($scheme)) ?></b></div>
            <div class="fact"><span>PHP runtime</span><b><?= $escape(PHP_VERSION) ?></b></div>
            <div class="fact"><span>Gateway</span><b><?= $escape($serverSoftware) ?></b></div>
            <div class="fact"><span>Document root</span><b><?= $escape($documentRoot) ?></b></div>
        </section>
        <footer><span>One dynamic template. No domain values are hardcoded.</span><span><?= $escape(gmdate('Y-m-d H:i')) ?> UTC</span></footer>
    </main>
</body>
</html>
