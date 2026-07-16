<?php
declare(strict_types=1);

function serverpanelRequestIsSecure(): bool
{
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '') {
        return $forwardedProto === 'https';
    }

    return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

session_name('SignonSession');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => serverpanelRequestIsSecure(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');

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
            'secure' => serverpanelRequestIsSecure(),
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
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>phpMyAdmin Logged Out</title>
    </head>
    <body>
        <p>Logged out from phpMyAdmin.</p>
        <p>Start login again from ServerPanel.</p>
    </body>
    </html>
    <?php
    exit;
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

    $username = trim((string) ($input['pma_username'] ?? ''));
    $password = (string) ($input['pma_password'] ?? '');
    $host = trim((string) ($input['pma_host'] ?? '127.0.0.1'));
    $database = trim((string) ($input['db'] ?? ''));

    if (strcasecmp($host, 'localhost') === 0) {
        $host = '127.0.0.1';
    }

    if ($username === '' || $password === '') {
        if ($wantsJson) {
            jsonResponse([
                'success' => false,
                'message' => 'Missing phpMyAdmin username or password.',
            ], 422);
        }

        echo 'Missing phpMyAdmin credentials.';
        exit;
    }

    if (strcasecmp($username, 'root') === 0) {
        if ($wantsJson) {
            jsonResponse([
                'success' => false,
                'message' => 'Root login is disabled for phpMyAdmin auto-login.',
            ], 403);
        }

        echo 'Root login is disabled for phpMyAdmin auto-login.';
        exit;
    }

    $_SESSION['PMA_single_signon_user'] = $username;
    $_SESSION['PMA_single_signon_password'] = $password;
    $_SESSION['PMA_single_signon_host'] = $host !== '' ? $host : '127.0.0.1';
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
        echo 'Auto login session not found. Please start from panel again.';
        exit;
    }

    $redirect = $target;
    $database = (string) ($_SESSION['PMA_single_signon_db'] ?? '');
    if ($database !== '') {
        $redirect .= '?db=' . rawurlencode($database);
    }
    header('Location: ' . $redirect);
    exit;
}

http_response_code(400);
echo 'Start phpMyAdmin from ServerPanel to continue.';
