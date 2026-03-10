<?php

declare(strict_types=1);

/**
 * Simple one-time token store for phpMyAdmin signon.
 *
 * Storage format: a PHP file returning an array, to reduce the chance of
 * accidentally serving secrets as static JSON/text from a misconfigured server.
 *
 * Tokens are random and stored as SHA-256 hashes; payload expires quickly.
 */

const PMA_TOKEN_DEFAULT_TTL_SECONDS = 900;
const PMA_TOKEN_MAX_TTL_SECONDS = 1800;

function pma_token_issue(array $payload, int $ttlSeconds = PMA_TOKEN_DEFAULT_TTL_SECONDS): string
{
    $ttlSeconds = max(60, min(PMA_TOKEN_MAX_TTL_SECONDS, $ttlSeconds));
    $token = pma_token_generate();
    $hash = hash('sha256', $token);

    $lockHandle = pma_token_lock();
    try {
        $store = pma_token_load_store();
        $now = time();
        pma_token_cleanup($store, $now);

        $store[$hash] = [
            'exp' => $now + $ttlSeconds,
            'payload' => [
                'username' => (string) ($payload['username'] ?? ''),
                'password' => (string) ($payload['password'] ?? ''),
                'host' => (string) ($payload['host'] ?? '127.0.0.1'),
                'port' => (string) ($payload['port'] ?? ''),
                'db' => (string) ($payload['db'] ?? ''),
            ],
        ];

        pma_token_write_store($store);
    } finally {
        pma_token_unlock($lockHandle);
    }

    return $token;
}

function pma_token_consume(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) > 256) {
        return null;
    }

    $hash = hash('sha256', $token);
    $lockHandle = pma_token_lock();
    try {
        $store = pma_token_load_store();
        $now = time();
        pma_token_cleanup($store, $now);

        if (! isset($store[$hash]) || ! is_array($store[$hash])) {
            return null;
        }

        $row = $store[$hash];
        unset($store[$hash]);
        pma_token_write_store($store);

        if (! is_array($row) || (int) ($row['exp'] ?? 0) < $now) {
            return null;
        }

        $payload = $row['payload'] ?? null;
        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    } finally {
        pma_token_unlock($lockHandle);
    }
}

function pma_token_store_path(): string
{
    return __DIR__.DIRECTORY_SEPARATOR.'tokens.php';
}

function pma_token_lock_path(): string
{
    return __DIR__.DIRECTORY_SEPARATOR.'tokens.lock';
}

/**
 * @return resource|null
 */
function pma_token_lock()
{
    $path = pma_token_lock_path();
    $handle = @fopen($path, 'c+');
    if (! is_resource($handle)) {
        return null;
    }

    @flock($handle, LOCK_EX);
    return $handle;
}

/**
 * @param resource|null $handle
 */
function pma_token_unlock($handle): void
{
    if (! is_resource($handle)) {
        return;
    }

    @flock($handle, LOCK_UN);
    @fclose($handle);
}

/**
 * @return array<string, array{exp:int, payload:array<string, string>}>
 */
function pma_token_load_store(): array
{
    $path = pma_token_store_path();
    if (! is_file($path)) {
        return [];
    }

    $loaded = require $path;
    if (! is_array($loaded)) {
        return [];
    }

    return $loaded;
}

/**
 * @param array<string, array{exp:int, payload:array<string, string>}> $store
 */
function pma_token_write_store(array $store): void
{
    $path = pma_token_store_path();
    $tmp = $path.'.tmp';

    $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($store, true).";\n";
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write token store');
    }

    if (! @rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to persist token store');
    }
}

/**
 * @param array<string, array{exp:int, payload:array<string, string>}> $store
 */
function pma_token_cleanup(array &$store, int $now): void
{
    foreach ($store as $key => $row) {
        if (! is_array($row) || (int) ($row['exp'] ?? 0) < $now) {
            unset($store[$key]);
        }
    }
}

function pma_token_generate(): string
{
    $raw = random_bytes(32);
    $b64 = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return $b64;
}

