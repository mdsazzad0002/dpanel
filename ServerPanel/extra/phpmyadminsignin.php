<?php
declare(strict_types=1);

session_name('SignonSession');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');

/**
 * Allow panel origins from same host (any port), plus optional explicit list.
 * Set PMA_ALLOWED_ORIGINS as comma-separated full origins if needed.
 */
$allowedOrigins = [];
$configuredOrigins = trim((string) getenv('PMA_ALLOWED_ORIGINS'));
if ($configuredOrigins !== '') {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $configuredOrigins)), static fn ($item) => $item !== ''));
}
$allowedOrigins = array_values(array_unique(array_merge($allowedOrigins, [
    'http://127.0.0.1:8000',
    'http://localhost:8000',
])));

$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
$isAllowedOrigin = in_array($origin, $allowedOrigins, true);
if (!$isAllowedOrigin && $origin !== '') {
    $originHost = (string) parse_url($origin, PHP_URL_HOST);
    $serverHostRaw = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $serverHost = strtolower(trim((string) preg_replace('/:\d+$/', '', $serverHostRaw)));
    $normalizedOriginHost = strtolower(trim($originHost));
    if ($normalizedOriginHost !== '' && $serverHost !== '' && $normalizedOriginHost === $serverHost) {
        $isAllowedOrigin = true;
    }
}

if ($isAllowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function renderHtmlView(
    string $title,
    string $headline,
    string $message,
    string $tone = 'info',
    int $status = 200
): void {
    $tone = strtolower($tone);
    $palette = match ($tone) {
        'success' => ['accent' => '#0f766e', 'soft' => '#99f6e4'],
        'danger' => ['accent' => '#b91c1c', 'soft' => '#fecaca'],
        'warning' => ['accent' => '#92400e', 'soft' => '#fde68a'],
        default => ['accent' => '#1d4ed8', 'soft' => '#bfdbfe'],
    };

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeHeadline = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $accent = htmlspecialchars($palette['accent'], ENT_QUOTES, 'UTF-8');
    $soft = htmlspecialchars($palette['soft'], ENT_QUOTES, 'UTF-8');

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <style>
        :root {
            --ink: #0f172a;
            --muted: #475569;
            --surface: #ffffff;
            --accent: {$accent};
            --soft: {$soft};
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 10% 10%, rgba(59, 130, 246, 0.2), transparent 40%),
                radial-gradient(circle at 90% 90%, rgba(16, 185, 129, 0.18), transparent 45%),
                linear-gradient(160deg, #e2e8f0 0%, #f8fafc 42%, #f1f5f9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            width: min(560px, 100%);
            background: var(--surface);
            border: 1px solid #cbd5e1;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.13);
            position: relative;
            overflow: hidden;
        }
        .card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 8px;
            background: linear-gradient(90deg, var(--soft), var(--accent));
        }
        h1 {
            margin: 8px 0 10px;
            font-size: clamp(1.25rem, 2.4vw, 1.65rem);
            letter-spacing: 0.02em;
        }
        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
            font-size: 0.96rem;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--accent);
            font-weight: 700;
            background: color-mix(in srgb, var(--soft) 52%, white);
            border: 1px solid var(--soft);
            padding: 6px 10px;
            border-radius: 999px;
        }
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 0 6px color-mix(in srgb, var(--soft) 72%, transparent);
        }
    </style>
</head>
<body>
    <section class="card" role="status" aria-live="polite">
        <span class="badge"><span class="dot"></span>ServerPanel Bridge</span>
        <h1>{$safeHeadline}</h1>
        <p>{$safeMessage}</p>
    </section>
</body>
</html>
HTML;
    exit;
}

function clearSignonSession(): void
{
    unset(
        $_SESSION['PMA_single_signon_user'],
        $_SESSION['PMA_single_signon_password'],
        $_SESSION['PMA_single_signon_host'],
        $_SESSION['PMA_single_signon_db']
    );

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

$action = (string) ($_GET['action'] ?? '');
$selfUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
$target = rtrim(dirname($selfUrl), '/') . '/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action !== 'redirect') {
    clearSignonSession();

    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        jsonResponse([
            'success' => true,
            'message' => 'Logged out from phpMyAdmin.',
        ]);
    }

    http_response_code(200);
    renderHtmlView(
        'phpMyAdmin Logged Out',
        'Signed Out',
        'You are logged out from phpMyAdmin. Start again from ServerPanel.',
        'success',
        200
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $isJson = str_contains($contentType, 'application/json');
    $wantsJson = $isJson || str_contains($accept, 'application/json');

    $input = [];

    if ($isJson) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    if (!is_array($input) || $input === []) {
        $input = $_POST;
    }

    $username = trim((string) ($input['pma_username'] ?? $input['username'] ?? $input['user'] ?? ''));
    $password = (string) ($input['pma_password'] ?? $input['password'] ?? $input['pass'] ?? '');
    $host = trim((string) ($input['pma_host'] ?? $input['host'] ?? '127.0.0.1'));
    $database = trim((string) ($input['db'] ?? $input['database'] ?? $input['dbname'] ?? ''));
    $portRaw = trim((string) ($input['pma_port'] ?? $input['port'] ?? ''));

    if ($host !== '' && preg_match('/^(.+):([0-9]{1,5})$/', $host, $hostParts) === 1) {
        $host = trim((string) ($hostParts[1] ?? ''));
        if ($portRaw === '') {
            $portRaw = trim((string) ($hostParts[2] ?? ''));
        }
    }

    if (strcasecmp($host, 'localhost') === 0) {
        $host = '127.0.0.1';
    }
    if ($host === '') {
        $host = '127.0.0.1';
    }

    if ($username === '' || $password === '') {
        if ($wantsJson) {
            jsonResponse([
                'success' => false,
                'message' => 'Missing phpMyAdmin username or password.',
            ], 422);
        }

        renderHtmlView(
            'Credentials Required',
            'Missing Credentials',
            'phpMyAdmin username and password are required for secure sign-in.',
            'warning',
            422
        );
    }

    if (strcasecmp($username, 'root') === 0) {
        if ($wantsJson) {
            jsonResponse([
                'success' => false,
                'message' => 'Root login is disabled for phpMyAdmin auto-login.',
            ], 403);
        }

        renderHtmlView(
            'Access Restricted',
            'Root Login Disabled',
            'Use a dedicated database user from ServerPanel. Root auto-login is blocked for safety.',
            'danger',
            403
        );
    }

    $_SESSION['PMA_single_signon_user'] = $username;
    $_SESSION['PMA_single_signon_password'] = $password;
    $_SESSION['PMA_single_signon_host'] = $host !== '' ? $host : '127.0.0.1';
    if ($portRaw !== '' && ctype_digit($portRaw)) {
        $port = (int) $portRaw;
        if ($port >= 1 && $port <= 65535) {
            $_SESSION['PMA_single_signon_port'] = $port;
        } else {
            unset($_SESSION['PMA_single_signon_port']);
        }
    } else {
        unset($_SESSION['PMA_single_signon_port']);
    }
    if ($database !== '') {
        $_SESSION['PMA_single_signon_db'] = $database;
    } else {
        unset($_SESSION['PMA_single_signon_db']);
    }

    $redirect = $target;
    if ($database !== '') {
        $redirect .= '?db=' . rawurlencode($database);
    }
    session_write_close();

    if ($wantsJson) {
        jsonResponse([
            'success' => true,
            'message' => 'Session created successfully.',
            'redirect' => $redirect,
        ]);
    }

    header('Location: ' . $redirect);
    exit;
}

if ($action === 'redirect') {
    $username = (string) ($_SESSION['PMA_single_signon_user'] ?? '');
    if ($username === '') {
        renderHtmlView(
            'Session Missing',
            'Sign-in Session Expired',
            'Auto-login session was not found. Start phpMyAdmin again from ServerPanel.',
            'warning',
            401
        );
    }

    $redirect = $target;
    $database = (string) ($_SESSION['PMA_single_signon_db'] ?? '');
    if ($database !== '') {
        $redirect .= '?db=' . rawurlencode($database);
    }
    header('Location: ' . $redirect);
    exit;
}

renderHtmlView(
    'Bridge Ready',
    'Open from ServerPanel',
    'This bridge is managed by ServerPanel. Start phpMyAdmin from the panel dashboard.',
    'info',
    400
);
