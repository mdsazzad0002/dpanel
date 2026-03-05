<?php
declare(strict_types=1);

session_start();

header('X-Frame-Options: SAMEORIGIN');

$allowedOrigins = [
    'http://127.0.0.1:8000',
    'http://localhost:8000',
];

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, $allowedOrigins, true)) {
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

$action = (string) ($_GET['action'] ?? '');
$selfUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');

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
    $host = trim((string) ($input['pma_host'] ?? 'localhost'));
    $database = trim((string) ($input['db'] ?? ''));

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

    $_SESSION['admin_pma_autologin'] = [
        'username' => $username,
        'password' => $password,
        'host' => $host !== '' ? $host : 'localhost',
        'database' => $database,
        'created_at' => time(),
    ];

    $redirect = $selfUrl . '?action=redirect';

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
    $sessionData = $_SESSION['admin_pma_autologin'] ?? null;
    if (!is_array($sessionData)) {
        echo 'Auto login session not found. Please start from panel again.';
        exit;
    }

    $username = (string) ($sessionData['username'] ?? '');
    $password = (string) ($sessionData['password'] ?? '');
    $host = (string) ($sessionData['host'] ?? 'localhost');
    $database = (string) ($sessionData['database'] ?? '');

    unset($_SESSION['admin_pma_autologin']);

    $target = rtrim(dirname($selfUrl), '/') . '/index.php';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>phpMyAdmin Auto Login</title>
    </head>
    <body>
        <p>Logging in to phpMyAdmin...</p>
        <form id="pma-login-form" method="post" action="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="pma_username" value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="pma_password" value="<?php echo htmlspecialchars($password, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="server" value="<?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="db" value="<?php echo htmlspecialchars($database, ENT_QUOTES, 'UTF-8'); ?>">
        </form>
        <script>
            document.getElementById('pma-login-form').submit();
        </script>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(405);
echo 'Method not allowed.';