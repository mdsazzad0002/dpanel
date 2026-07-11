<?php

function serverpanelResolveSignonSecret(): string
{
    $sharedSecretPath = realpath(__DIR__.'/../../dpanel/storage/app/phpmyadmin_signon.secret');
    if ($sharedSecretPath !== false) {
        return hash('sha256', $sharedSecretPath.'|ServerPanel|phpMyAdminSignon');
    }

    return hash('sha256', __DIR__.'/../../dpanel/storage/app/phpmyadmin_signon.secret|ServerPanel|phpMyAdminSignon');
}

function serverpanelRequestIsSecure(): bool
{
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '') {
        return $forwardedProto === 'https';
    }

    return ! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

$secretSeed = trim((string) getenv('PMA_BLOWFISH_SECRET'));
if ($secretSeed === '') {
    $secretSeed = hash('sha256', __FILE__.'|ServerPanel|phpMyAdmin');
}
if (! preg_match('/^[0-9a-f]{64}$/i', $secretSeed)) {
    $secretSeed = hash('sha256', $secretSeed);
}

$scheme = serverpanelRequestIsSecure() ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$proxyBase = trim((string) ($_SERVER['HTTP_X_SERVERPANEL_PMA_BASE'] ?? ''));
if ($proxyBase !== '' && ! str_starts_with($proxyBase, '/')) {
    $proxyBase = '/'.$proxyBase;
}

$directBase = '/phpmyadmin';
$baseUri = $proxyBase !== '' ? rtrim($proxyBase, '/').'/' : $directBase.'/';

$signonUrl = $scheme.'://'.$host.$baseUri.'index.php';
$logoutUrl = $scheme.'://'.$host.$baseUri;

// Force a stable default language and clear stale/unsupported values early.
$_GET['lang'] = 'en';
$_POST['lang'] = 'en';
$_REQUEST['lang'] = 'en';
$_COOKIE['pma_lang'] = 'en';

$cfg['blowfish_secret'] = function_exists('hex2bin')
    ? hex2bin(substr($secretSeed, 0, 64))
    : $secretSeed;

$cfg['PMA_ABSOLUTE_URI'] = rtrim($scheme.'://'.$host.$baseUri, '/').'/';
$cfg['DefaultLang'] = 'en';

function serverpanelBase64UrlDecode(string $value): string|false
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($value, true);
}

function serverpanelDecodeSignonToken(string $token): ?array
{
    $secret = serverpanelResolveSignonSecret();
    if ($secret === '') {
        return null;
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$encodedPayload, $signature] = $parts;
    $expectedSignature = hash_hmac('sha256', $encodedPayload, $secret);
    if (! hash_equals($expectedSignature, $signature)) {
        return null;
    }

    $payloadJson = serverpanelBase64UrlDecode($encodedPayload);
    if ($payloadJson === false) {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (! is_array($payload)) {
        return null;
    }

    $exp = (int) ($payload['exp'] ?? 0);
    if ($exp < time()) {
        return null;
    }

    return $payload;
}

function serverpanelApplySignonServerConfig(array &$cfg, int $index, array $payload, string $signonSessionName, string $signonUrl): void
{
    $cfg['Servers'][$index]['auth_type'] = 'signon';
    $cfg['Servers'][$index]['host'] = (string) ($payload['host'] ?? '127.0.0.1');
    $cfg['Servers'][$index]['port'] = (int) ($payload['port'] ?? 3306);
    $cfg['Servers'][$index]['verbose'] = 'ServerPanel';
    $cfg['Servers'][$index]['AllowNoPassword'] = false;
    $cfg['Servers'][$index]['SignonSession'] = $signonSessionName;
    $cfg['Servers'][$index]['SignonURL'] = $signonUrl;
    $cfg['Servers'][$index]['SignonScript'] = '';

    if (! empty($payload['db'])) {
        $cfg['Servers'][$index]['only_db'] = (string) $payload['db'];
    }
}

function serverpanelSeedSignonSession(array $payload, string $sessionName): void
{
    $previousSessionName = session_name();
    $previousCookieParams = session_get_cookie_params();

    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => serverpanelRequestIsSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        session_set_cookie_params($cookieParams);
    } else {
        session_set_cookie_params(
            $cookieParams['lifetime'],
            $cookieParams['path'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    session_name($sessionName);
    session_start();
    $_COOKIE[$sessionName] = session_id();
    $_REQUEST[$sessionName] = session_id();

    $_SESSION['PMA_single_signon_user'] = (string) ($payload['user'] ?? '');
    $_SESSION['PMA_single_signon_password'] = (string) ($payload['pass'] ?? '');
    $_SESSION['PMA_single_signon_host'] = (string) ($payload['host'] ?? '127.0.0.1');
    $_SESSION['PMA_single_signon_port'] = (int) ($payload['port'] ?? 3306);
    $_SESSION['PMA_single_signon_cfgupdate'] = [
        'verbose' => 'ServerPanel',
        'only_db' => ! empty($payload['db']) ? (string) $payload['db'] : '',
    ];
    $_SESSION['PMA_single_signon_HMAC_secret'] = hash('sha256', serverpanelResolveSignonSecret().'|'.$sessionName.'|HMAC');
    $_SESSION['PMA_single_signon_token'] = bin2hex(random_bytes(16));

    session_write_close();

    if ($previousSessionName !== '') {
        session_name($previousSessionName);
    }

    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        session_set_cookie_params($previousCookieParams);
    } else {
        session_set_cookie_params(
            $previousCookieParams['lifetime'] ?? 0,
            $previousCookieParams['path'] ?? '/',
            $previousCookieParams['domain'] ?? '',
            $previousCookieParams['secure'] ?? false,
            $previousCookieParams['httponly'] ?? false
        );
    }
}

function serverpanelDebugEnabled(): bool
{
    $env = strtolower(trim((string) getenv('PHPMYADMIN_DEBUG')));
    if ($env !== '') {
        return in_array($env, ['1', 'true', 'yes', 'on'], true);
    }

    return (string) ($_GET['spm_debug'] ?? $_POST['spm_debug'] ?? '') === '1';
}

function serverpanelDebugLog(string $message, array $context = []): void
{
    if (! serverpanelDebugEnabled()) {
        return;
    }

    $logDir = realpath(__DIR__.'/../../dpanel/storage/logs');
    if ($logDir === false) {
        $logDir = __DIR__.'/../../dpanel/storage/logs';
    }

    if (! is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $line = '['.date('c').'] '.$message;
    if ($context !== []) {
        $line .= ' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $line .= PHP_EOL;

    @file_put_contents(rtrim($logDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'phpmyadmin-debug.log', $line, FILE_APPEND);
}

$signonToken = (string) ($_GET['spm'] ?? $_POST['spm'] ?? '');
$signonPayload = $signonToken !== '' ? serverpanelDecodeSignonToken($signonToken) : null;
$panelCookieName = trim((string) getenv('SERVERPANEL_PANEL_COOKIE'));
if ($panelCookieName === '') {
    $panelCookieName = 'panel_session_proof';
}
$panelCookieValue = (string) ($_COOKIE[$panelCookieName] ?? '');

serverpanelDebugLog('bootstrap', [
    'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'has_spm' => $signonToken !== '',
    'spm_len' => strlen($signonToken),
    'panel_cookie' => $panelCookieValue !== '',
    'signon_payload' => is_array($signonPayload),
]);

if (is_array($signonPayload)) {
    $cfg['Servers'] = [];
    $signonSessionName = 'SignonSession';
    serverpanelSeedSignonSession($signonPayload, $signonSessionName);
    serverpanelApplySignonServerConfig($cfg, 1, $signonPayload, $signonSessionName, $signonUrl);

    serverpanelDebugLog('applied-signon', [
        'db' => (string) ($signonPayload['db'] ?? ''),
        'user' => (string) ($signonPayload['user'] ?? ''),
        'host' => (string) ($signonPayload['host'] ?? ''),
        'port' => (int) ($signonPayload['port'] ?? 0),
        'session' => $signonSessionName,
    ]);
}
