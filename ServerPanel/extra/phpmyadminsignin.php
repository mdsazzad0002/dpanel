<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Secure Session Setup
|--------------------------------------------------------------------------
*/

$isSecureRequest =
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
    strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

ini_set('session.use_strict_mode', '1');

session_name('SignonSession');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isSecureRequest,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

header('X-Frame-Options: SAMEORIGIN');

/*
|--------------------------------------------------------------------------
| Allowed Panel Origins (CORS)
|--------------------------------------------------------------------------
*/

$allowedOrigins = [];
$configuredOrigins = trim((string) getenv('PMA_ALLOWED_ORIGINS'));

if ($configuredOrigins !== '') {
    $allowedOrigins = array_values(
        array_filter(
            array_map('trim', explode(',', $configuredOrigins)),
            fn ($item) => $item !== ''
        )
    );
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
    $serverHost = strtolower(trim((string) preg_replace('/:\\d+$/', '', $serverHostRaw)));
    $normalizedOriginHost = strtolower(trim($originHost));

    if ($normalizedOriginHost !== '' && $serverHost !== '' && $normalizedOriginHost === $serverHost) {
        $isAllowedOrigin = true;
    }
}

if ($isAllowedOrigin) {
    header('Access-Control-Allow-Origin: '.$origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Accept, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Utilities
|--------------------------------------------------------------------------
*/

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function wantsJson(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest' || str_contains($contentType, 'application/json');
}

function clearSignonSession(): void
{
    unset(
        $_SESSION['PMA_single_signon_user'],
        $_SESSION['PMA_single_signon_password'],
        $_SESSION['PMA_single_signon_host'],
        $_SESSION['PMA_single_signon_port'],
        $_SESSION['PMA_single_signon_db']
    );

    session_regenerate_id(true);
}

function absoluteUrl(string $pathOrUrl): string
{
    $candidate = trim($pathOrUrl);
    if ($candidate === '') {
        return $candidate;
    }

    if (preg_match('#^https?://#i', $candidate)) {
        return $candidate;
    }

    $isSecure =
        (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ||
        strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $scheme = $isSecure ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        return $candidate;
    }

    $path = $candidate[0] === '/' ? $candidate : '/'.$candidate;
    return $scheme.'://'.$host.$path;
}

function requestBearerToken(): string
{
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $authorization = trim($authorization);
    if ($authorization === '') {
        return '';
    }

    if (preg_match('/^Bearer\\s+(.*)$/i', $authorization, $m)) {
        return trim((string) ($m[1] ?? ''));
    }

    return '';
}

/*
|--------------------------------------------------------------------------
| phpMyAdmin target
|--------------------------------------------------------------------------
*/

$scriptDir = (string) dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/phpmyadminsignin.php'));
$target = rtrim($scriptDir, '/').'/index.php';
$action = (string) ($_GET['action'] ?? '');
$selfPath = rtrim($scriptDir, '/').'/phpmyadminsignin.php';

/*
|--------------------------------------------------------------------------
| Token issue endpoint (server-to-server)
|--------------------------------------------------------------------------
|
| ServerPanel can request a one-time token so DB credentials never need to
| transit the browser. Protect this with an env secret.
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'issue') {
    $expected = trim((string) getenv('PMA_SIGNON_ISSUE_SECRET'));
    if ($expected === '') {
        jsonResponse(['success' => false, 'message' => 'Token issuing is disabled'], 404);
    }

    $provided = requestBearerToken();
    if ($provided === '') {
        $provided = trim((string) ($_SERVER['HTTP_X_SERVERPANEL_SIGNON'] ?? ''));
    }

    if ($provided === '' || ! hash_equals($expected, $provided)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
    }

    try {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $input = [];

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        if ($input === []) {
            $input = $_POST;
        }

        $username = trim((string) ($input['username'] ?? $input['user'] ?? ''));
        $password = (string) ($input['password'] ?? $input['pass'] ?? '');
        $host = trim((string) ($input['host'] ?? '127.0.0.1'));
        $database = trim((string) ($input['db'] ?? ''));
        $ttl = (int) ($input['ttl'] ?? 900);

        if ($username === '' || $password === '') {
            throw new RuntimeException('Missing username or password');
        }

        if (strtolower($username) === 'root') {
            throw new RuntimeException('Root login disabled for security');
        }

        $tokenDb = __DIR__.'/autologin/token_db.php';
        if (! is_file($tokenDb)) {
            throw new RuntimeException('Token support not installed');
        }
        require_once $tokenDb;

        $token = pma_token_issue([
            'username' => $username,
            'password' => $password,
            'host' => $host !== '' ? $host : '127.0.0.1',
            'db' => $database,
        ], $ttl);

        $sep = str_contains($selfPath, '?') ? '&' : '?';
        jsonResponse([
            'success' => true,
            'token' => $token,
            'redirect' => absoluteUrl($selfPath.$sep.'token='.rawurlencode($token)),
        ]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
}

/*
|--------------------------------------------------------------------------
| Token-based signon (GET)
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['token'])) {
    $token = (string) $_GET['token'];

    $tokenDb = __DIR__.'/autologin/token_db.php';
    if (!is_file($tokenDb)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Token support not installed.\n";
        exit;
    }

    require $tokenDb;

    $row = pma_token_consume($token);
    if (!is_array($row)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid or expired token.\n";
        exit;
    }

    $_SESSION['PMA_single_signon_user'] = (string) ($row['username'] ?? '');
    $_SESSION['PMA_single_signon_password'] = (string) ($row['password'] ?? '');
    $_SESSION['PMA_single_signon_host'] = (string) ($row['host'] ?? '127.0.0.1');
    $_SESSION['PMA_single_signon_port'] = (string) ($row['port'] ?? '');
    $_SESSION['PMA_single_signon_db'] = (string) ($row['db'] ?? '');

    session_write_close();

    $redirect = $target;
    if (!empty($_SESSION['PMA_single_signon_db'])) {
        $redirect .= '?db='.rawurlencode((string) $_SESSION['PMA_single_signon_db']);
    }

    header('Location: '.absoluteUrl($redirect), true, 302);
    exit;
}

/*
|--------------------------------------------------------------------------
| Logout handler
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $action !== 'redirect' && !isset($_GET['token'])) {
    clearSignonSession();

    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $isSecureRequest,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo "<h2>Logged out from phpMyAdmin</h2>";
    echo "<p>Please return to ServerPanel.</p>";
    exit;
}

/*
|--------------------------------------------------------------------------
| Handle Login POST (JSON or form)
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $input = [];

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }

        if ($input === []) {
            $input = $_POST;
        }

        if (isset($input['token'])) {
            $tokenDb = __DIR__.'/autologin/token_db.php';
            if (!is_file($tokenDb)) {
                throw new RuntimeException('Token support not installed');
            }
            require $tokenDb;
            $row = pma_token_consume((string) $input['token']);
            if (!is_array($row)) {
                throw new RuntimeException('Invalid or expired token');
            }

            $username = trim((string) ($row['username'] ?? ''));
            $password = (string) ($row['password'] ?? '');
            $host = trim((string) ($row['host'] ?? '127.0.0.1'));
            $database = trim((string) ($row['db'] ?? ''));
        } else {
            $username = trim((string) ($input['username'] ?? $input['user'] ?? ''));
            $password = (string) ($input['password'] ?? $input['pass'] ?? '');
            $host = trim((string) ($input['host'] ?? '127.0.0.1'));
            $database = trim((string) ($input['db'] ?? ''));
        }

        if ($username === '' || $password === '') {
            throw new RuntimeException('Missing username or password');
        }

        if (strtolower($username) === 'root') {
            throw new RuntimeException('Root login disabled for security');
        }

        $_SESSION['PMA_single_signon_user'] = $username;
        $_SESSION['PMA_single_signon_password'] = $password;
        $_SESSION['PMA_single_signon_host'] = $host !== '' ? $host : '127.0.0.1';
        if ($database !== '') {
            $_SESSION['PMA_single_signon_db'] = $database;
        }

        session_write_close();

        $redirect = $target;
        if ($database !== '') {
            $redirect .= '?db='.rawurlencode($database);
        }

        $absoluteRedirect = absoluteUrl($redirect);

        if (wantsJson()) {
            jsonResponse([
                'success' => true,
                'redirect' => $absoluteRedirect,
            ]);
        }

        header('Location: '.$absoluteRedirect, true, 302);
        exit;
    } catch (Throwable $e) {
        if (wantsJson()) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }

        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo "<h2>phpMyAdmin Auto Login Error</h2>";
        echo "<p>".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."</p>";
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Redirect handler
|--------------------------------------------------------------------------
*/

if ($action === 'redirect') {
    $username = (string) ($_SESSION['PMA_single_signon_user'] ?? '');
    if ($username === '') {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Session expired. Open phpMyAdmin from ServerPanel.\n";
        exit;
    }

    $redirect = $target;
    if (!empty($_SESSION['PMA_single_signon_db'])) {
        $redirect .= '?db='.rawurlencode((string) $_SESSION['PMA_single_signon_db']);
    }

    header('Location: '.absoluteUrl($redirect), true, 302);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "phpMyAdmin Signon Helper Ready\n";
