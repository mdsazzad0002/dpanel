<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\Filemanager\FilemanagerService;

/**
 * Issues one-time WordPress auto-login links from dPanel. A per-site secret
 * signs a short-lived token; a generated mu-plugin verifies that signature
 * inside WordPress at the 'init' hook and logs the visitor in as the site's
 * first administrator, with no shared credentials ever leaving dPanel.
 */
class WordpressSsoService
{
    private const TOKEN_TTL_SECONDS = 60;

    public function __construct(
        private readonly FilemanagerService $filemanagerService,
        private readonly WordpressInstallService $wordpressInstallService,
    ) {
    }

    /**
     * @param array<string, mixed> $website
     */
    public function generateLoginUrl(array $website): string
    {
        $rootPath = $this->wordpressInstallService->resolveInstallationRoot($website);
        if ($rootPath === '' || ! $this->wordpressInstallService->hasWordPressFiles($rootPath)) {
            throw new \RuntimeException('WordPress is not installed for this website yet.');
        }

        $domain = strtolower(trim((string) ($website['domain'] ?? '')));
        $siteOwner = (string) ($website['site_owner'] ?? '');
        if ($domain === '' || $siteOwner === '') {
            throw new \RuntimeException('Website domain or owner is missing.');
        }

        $model = Website::query()->findOrFail($website['id']);
        $secret = $this->ensureSecret($model);

        $this->deployMuPlugin($siteOwner, $rootPath, $secret);

        $token = $this->signPayload([
            'host' => $domain,
            'exp' => time() + self::TOKEN_TTL_SECONDS,
            'nonce' => bin2hex(random_bytes(12)),
        ], $secret);

        $scheme = ! empty($website['enable_ssl']) ? 'https' : 'http';

        return "{$scheme}://{$domain}/?dpanel_sso={$token}";
    }

    private function ensureSecret(Website $model): string
    {
        $secret = trim((string) $model->wordpress_sso_secret);
        if ($secret !== '') {
            return $secret;
        }

        $secret = bin2hex(random_bytes(32));
        $model->forceFill(['wordpress_sso_secret' => $secret])->save();

        return $secret;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signPayload(array $payload, string $secret): string
    {
        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payloadJson, $secret, true);

        return $this->base64UrlEncode($payloadJson).'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function deployMuPlugin(string $siteOwner, string $rootPath, string $secret): void
    {
        $muPluginsDir = rtrim($rootPath, '/').'/wp-content/mu-plugins';
        $this->filemanagerService->ensureDirectoryExists($siteOwner, $muPluginsDir);

        $content = str_replace(
            '__DPANEL_SSO_SECRET_LITERAL__',
            var_export($secret, true),
            $this->muPluginTemplate(),
        );

        $this->filemanagerService->writeTextFile($siteOwner, $muPluginsDir.'/dpanel-sso.php', $content);
    }

    private function muPluginTemplate(): string
    {
        return <<<'PHP'
<?php
/**
 * dPanel SSO auto-login bridge. Auto-generated — dPanel rewrites this file
 * whenever a login link is issued; do not edit it by hand.
 */

if (! defined('DPANEL_SSO_SECRET')) {
    define('DPANEL_SSO_SECRET', __DPANEL_SSO_SECRET_LITERAL__);
}

add_action('init', function () {
    if (! isset($_GET['dpanel_sso'])) {
        return;
    }

    $token = (string) $_GET['dpanel_sso'];
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return;
    }

    [$payloadPart, $sigPart] = $parts;
    $payloadJson = base64_decode(strtr($payloadPart, '-_', '+/'), true);
    $signature = base64_decode(strtr($sigPart, '-_', '+/'), true);
    if ($payloadJson === false || $signature === false) {
        return;
    }

    $expected = hash_hmac('sha256', $payloadJson, DPANEL_SSO_SECRET, true);
    if (! hash_equals($expected, $signature)) {
        wp_die('This dPanel login link is invalid.', 'dPanel SSO', ['response' => 403]);
    }

    $payload = json_decode($payloadJson, true);
    if (! is_array($payload)) {
        return;
    }

    $nonce = (string) ($payload['nonce'] ?? '');
    $expiresAt = (int) ($payload['exp'] ?? 0);
    $host = strtolower((string) ($payload['host'] ?? ''));
    $requestHost = strtolower(strtok((string) ($_SERVER['HTTP_HOST'] ?? ''), ':'));

    if ($nonce === '' || $host === '' || $host !== $requestHost) {
        wp_die('This dPanel login link is invalid.', 'dPanel SSO', ['response' => 403]);
    }

    if ($expiresAt < time()) {
        wp_die('This dPanel login link has expired. Generate a new one from dPanel.', 'dPanel SSO', ['response' => 403]);
    }

    $usedKey = 'dpanel_sso_used_'.$nonce;
    if (get_transient($usedKey)) {
        wp_die('This dPanel login link has already been used.', 'dPanel SSO', ['response' => 403]);
    }
    set_transient($usedKey, 1, 300);

    $admins = get_users([
        'role' => 'administrator',
        'orderby' => 'ID',
        'order' => 'ASC',
        'number' => 1,
    ]);
    $user = $admins[0] ?? null;
    if (! $user) {
        wp_die('No administrator account was found to log in as.', 'dPanel SSO', ['response' => 403]);
    }

    wp_clear_auth_cookie();
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    wp_safe_redirect(admin_url());
    exit;
});

PHP;
    }
}
