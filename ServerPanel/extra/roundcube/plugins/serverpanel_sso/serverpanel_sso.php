<?php

/**
 * Roundcube plugin: serverpanel_sso
 *
 * Usage:
 * - Enable plugin in Roundcube config.
 * - Configure:
 *   - $config['serverpanel_sso_panel_url'] (POST endpoint)
 *   - $config['serverpanel_sso_secret'] (shared secret)
 * - Redirect users to Roundcube with: ?sso_token=...
 */

class serverpanel_sso extends rcube_plugin
{
    public function init(): void
    {
        $this->add_hook('startup', [$this, 'startup']);
    }

    public function startup(array $args): array
    {
        if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0) {
            return $args;
        }

        $token = trim((string) ($_GET['sso_token'] ?? ''));
        if ($token === '') {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        $panelUrl = trim((string) $rcmail->config->get('serverpanel_sso_panel_url', ''));
        $secret = trim((string) $rcmail->config->get('serverpanel_sso_secret', ''));
        if ($panelUrl === '' || $secret === '') {
            return $args;
        }

        $creds = $this->consume($panelUrl, $secret, $token);
        if (! is_array($creds) || ($creds['email'] ?? '') === '' || ($creds['password'] ?? '') === '') {
            return $args;
        }

        $email = (string) $creds['email'];
        $password = (string) $creds['password'];

        $ok = false;
        try {
            $ok = (bool) $rcmail->login($email, $password);
        } catch (Throwable $e) {
            $ok = false;
        }

        if (! $ok) {
            return $args;
        }

        $url = $rcmail->url(['_task' => 'mail']);
        header('Location: '.$url, true, 302);
        exit;
    }

    private function consume(string $url, string $secret, string $token): ?array
    {
        if (! function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        if (! $ch) {
            return null;
        }

        $body = json_encode(['token' => $token]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer '.$secret,
            'X-ServerPanel-SSO: '.$secret,
        ]);

        $raw = curl_exec($ch);
        $errno = (int) curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $code < 200 || $code >= 300 || ! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
            return null;
        }

        return [
            'email' => (string) ($decoded['email'] ?? ''),
            'password' => (string) ($decoded['password'] ?? ''),
        ];
    }
}

