<?php

namespace App\Http\Controllers;

use App\Support\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function __construct(private readonly SecuritySettings $settings)
    {
    }

    public function manager(): Response
    {
        return $this->renderManager('overview');
    }

    public function ports(): Response
    {
        return $this->renderManager('overview');
    }

    public function firewall(): Response
    {
        return $this->renderManager('overview');
    }

    public function ssh(): Response
    {
        return $this->renderManager('overview');
    }

    public function sshGuide(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'groups' => [
                    ['title' => 'Install and check', 'commands' => ['sudo dpanel ssh install', 'sudo dpanel ssh status']],
                    ['title' => 'Enable or disable SSH', 'commands' => ['sudo dpanel ssh enable', 'sudo dpanel ssh disable']],
                    ['title' => '1. Remove global SSH access', 'commands' => ['sudo dpanel ssh deny-global']],
                    ['title' => '2. Allow one specific IP', 'commands' => ['sudo dpanel ssh allow-ip 203.0.113.10']],
                    ['title' => '3. Remove one specific IP', 'commands' => ['sudo dpanel ssh remove-ip 203.0.113.10']],
                    ['title' => 'Review access rules', 'commands' => ['sudo dpanel ssh list-access']],
                    ['title' => 'Change SSH port safely', 'commands' => ['sudo dpanel ssh port 2222']],
                    ['title' => 'Secure login methods', 'commands' => ['sudo dpanel ssh root-login disable', 'sudo dpanel ssh root-login keys-only', 'sudo dpanel ssh password-auth disable']],
                ],
                'warning' => 'After allowing your IP, test it from a second terminal before removing global access. Keep the server console open to avoid lockout.',
            ],
        ]);
    }

    public function firewallGuide(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'groups' => [
                    ['title' => 'Install and configure', 'commands' => ['sudo dpanel module firewall install']],
                    ['title' => 'View firewall status', 'commands' => ['sudo ufw status numbered', 'sudo ufw status verbose']],
                    ['title' => 'Enable or disable UFW', 'commands' => ['sudo ufw enable', 'sudo ufw disable']],
                    ['title' => 'Allow a port', 'commands' => ['sudo ufw allow 443/tcp']],
                    ['title' => 'Remove an allowed port', 'commands' => ['sudo ufw delete allow 443/tcp']],
                    ['title' => 'Allow or remove a specific IP', 'commands' => ['sudo ufw allow from 203.0.113.10', 'sudo ufw delete allow from 203.0.113.10']],
                ],
                'warning' => 'Before enabling UFW, allow the active SSH port and keep the server console open to avoid lockout.',
            ],
        ]);
    }

    private function renderManager(string $section): Response
    {
        $state = $this->settings->read();

        return Inertia::render('SecurityManager', [
            'firewall' => $state['firewall'],
            'ssh' => $state['ssh'],
            'section' => $section,
            'telegram' => $state['telegram'],
            'twoFactor' => $state['two_factor'],
            'telegramBotConfigured' => trim((string) config('services.telegram.bot_token', '')) !== '',
            'telegramBotUsername' => trim((string) config('services.telegram.bot_username', '')),
        ]);
    }

    public function syncFromServer(Request $request): RedirectResponse|JsonResponse
    {
        $state = $this->settings->read();
        $live = $this->drustSecurityRequest();
        $state['firewall'] = array_merge($state['firewall'], (array) ($live['firewall'] ?? []));
        $state['ssh'] = array_merge($state['ssh'], (array) ($live['ssh'] ?? []));

        $this->settings->write($state);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Security settings synced from server.',
                'data' => [
                    'firewall' => $state['firewall'],
                    'ssh' => $state['ssh'],
                ],
            ]);
        }

        return redirect()->route('security.manager')->with('success', 'Security settings synced from server.');
    }

    public function live(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->drustSecurityRequest()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function updateFirewall(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_incoming' => ['required', 'in:allow,deny,reject'],
            'default_outgoing' => ['required', 'in:allow,deny,reject'],
            'allowed_ports' => ['nullable', 'array'],
            'allowed_ports.*' => ['integer', 'min:1', 'max:65535'],
        ]);

        try {
            $live = $this->drustSecurityRequest(array_merge(['action' => 'firewall_config'], $validated));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $state = $this->settings->read();
        $state['firewall'] = [
            'enabled' => (bool) $validated['enabled'],
            'default_incoming' => (string) $validated['default_incoming'],
            'default_outgoing' => (string) $validated['default_outgoing'],
            'allowed_ports' => collect($validated['allowed_ports'] ?? [])->unique()->values()->all(),
        ];
        $this->settings->write($state);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Firewall settings updated.',
                'data' => ['firewall' => $state['firewall'], 'live' => $live],
            ]);
        }

        return redirect()->route('security.manager')->with('success', 'Firewall settings updated.');
    }

    public function updateSsh(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'password_authentication' => ['required', 'in:On,Off'],
            'permit_root_login' => ['required', 'in:yes,no,prohibit-password,forced-commands-only'],
            'pubkey_authentication' => ['required', 'in:On,Off'],
        ]);

        try {
            $live = $this->drustSecurityRequest(array_merge(['action' => 'ssh_config'], $validated));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $state = $this->settings->read();
        $state['ssh'] = [
            'port' => (int) $validated['port'],
            'password_authentication' => (string) $validated['password_authentication'],
            'permit_root_login' => (string) $validated['permit_root_login'],
            'pubkey_authentication' => (string) $validated['pubkey_authentication'],
        ];
        $this->settings->write($state);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'SSH settings updated.',
                'data' => ['ssh' => $state['ssh'], 'live' => $live],
            ]);
        }

        return redirect()->route('security.manager')->with('success', 'SSH settings updated.');
    }

    public function updateSshService(Request $request): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $action = $validated['enabled'] ? 'enable' : 'disable';
        try {
            $live = $this->drustSecurityRequest(['action' => 'ssh_service', 'enabled' => $validated['enabled']]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "SSH service {$action}d.",
            'data' => $live,
        ]);
    }

    public function updateSshAccess(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ip' => ['required', 'ip'],
            'action' => ['required', Rule::in(['allow', 'revoke'])],
        ]);
        try {
            $live = $this->drustSecurityRequest([
                'action' => 'ssh_access',
                'ip' => $validated['ip'],
                'access_action' => $validated['action'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $validated['action'] === 'allow' ? 'SSH access allowed for the IP.' : 'SSH access revoked for the IP.',
            'data' => $live,
        ]);
    }

    public function updateTelegram(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'chat_id' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:512'],
        ]);

        $botToken = (string) config('services.telegram.bot_token', '');
        if ($botToken === '') {
            $error = 'Telegram bot token is not configured in the environment.';

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $error,
                ], 422);
            }

            return redirect()->route('security.manager')->with('error', $error);
        }

        $state = $this->settings->read();
        $state['telegram'] = [
            'enabled' => (bool) $validated['enabled'],
            'chat_id' => trim((string) $validated['chat_id']),
            'message' => trim((string) ($validated['message'] ?? 'Security alert from ServerPanel')),
        ];
        $this->settings->write($state);

        $webhookError = $this->syncTelegramWebhook($state['telegram']['enabled'], $botToken);
        if ($webhookError !== null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $webhookError,
                    'data' => ['telegram' => $state['telegram']],
                ], 422);
            }

            return redirect()->route('security.manager')->with('error', $webhookError);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Telegram settings saved.',
                'data' => ['telegram' => $state['telegram']],
            ]);
        }

        return redirect()->route('security.manager')->with('success', 'Telegram settings saved.');
    }

    public function updateTwoFactor(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'email' => ['required', 'boolean'],
            'telegram' => ['required', 'boolean'],
            'google_auth_app' => ['required', 'boolean'],
            'code_ttl_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'enforce_admin' => ['required', 'boolean'],
            'enforce_reseller' => ['required', 'boolean'],
        ]);

        $state = $this->settings->read();
        $state['two_factor'] = [
            'enabled' => true,
            'email' => (bool) $validated['email'],
            'telegram' => (bool) $validated['telegram'],
            'google_auth_app' => (bool) $validated['google_auth_app'],
            'code_ttl_minutes' => (int) $validated['code_ttl_minutes'],
            'enforce_admin' => true,
            'enforce_reseller' => true,
        ];
        $this->settings->write($state);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Two-factor settings saved.',
                'data' => ['two_factor' => $state['two_factor']],
            ]);
        }

        return redirect()->route('security.manager')->with('success', 'Two-factor settings saved.');
    }

    public function testTelegram(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:512'],
        ]);

        $botToken = (string) config('services.telegram.bot_token', '');
        if ($botToken === '') {
            $error = 'Telegram bot token is not configured.';

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $error,
                ], 422);
            }

            return redirect()->route('security.manager')->with('error', $error);
        }

        $chatId = trim((string) $validated['chat_id']);
        $message = trim((string) ($validated['message'] ?? 'Security alert from ServerPanel'));

        $response = Http::timeout(30)
            ->withoutVerifying()
            ->acceptJson()
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);

        if (! $response->successful()) {
            $description = (string) data_get($response->json(), 'description', 'Telegram request failed.');

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $description,
                ], 422);
            }

            return redirect()->route('security.manager')->with('error', $description);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Telegram test message sent.',
            ]);
        }

        return redirect()->route('security.manager')->with('success', 'Telegram test message sent.');
    }

    private function syncTelegramWebhook(bool $enabled, string $botToken): ?string
    {
        if ($botToken === '') {
            return null;
        }

        $webhookUrl = route('telegram.webhook', absolute: true);

        try {
            $response = Http::timeout(30)
                ->withoutVerifying()
                ->acceptJson()
                ->post("https://api.telegram.org/bot{$botToken}/".($enabled ? 'setWebhook' : 'deleteWebhook'), $enabled
                    ? [
                        'url' => $webhookUrl,
                        'drop_pending_updates' => true,
                    ]
                    : [
                        'drop_pending_updates' => true,
                    ]);

            if (! $response->successful()) {
                $description = (string) data_get($response->json(), 'description', 'Telegram webhook request failed.');

                return $description;
            }
        } catch (\Throwable $e) {
            return $e->getMessage() !== '' ? $e->getMessage() : 'Unable to sync Telegram webhook.';
        }

        return null;
    }

    /** @param array<string, mixed>|null $payload
     *  @return array<string, mixed>
     */
    private function drustSecurityRequest(?array $payload = null): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust security API is not configured.');
        }

        $request = Http::acceptJson()->asJson()->timeout((int) config('serverpanel.execution_api_timeout', 60));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $url = rtrim($baseUrl, '/').'/api/v1/security';
            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('drust security API request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'drust security API failed.'));
        }

        return (array) ($json['data'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function detectFirewallFromServer(): array
    {
        $output = $this->runCommand('ufw status');
        if ($output === '') {
            return [];
        }

        $enabled = str_contains(strtolower($output), 'status: active');
        $ports = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            if (preg_match('/^\s*([0-9]{1,5})(?:\/tcp|\/udp)?\s+ALLOW/i', (string) $line, $matches) === 1) {
                $port = (int) $matches[1];
                if ($port > 0 && $port <= 65535) {
                    $ports[] = $port;
                }
            }
        }

        $verbose = $this->runCommand('ufw status verbose');
        $defaultIncoming = 'deny';
        $defaultOutgoing = 'allow';
        if (preg_match('/Default:\s+([a-z]+)\s+\(incoming\),\s+([a-z]+)\s+\(outgoing\)/i', $verbose, $matches) === 1) {
            $defaultIncoming = strtolower((string) $matches[1]);
            $defaultOutgoing = strtolower((string) $matches[2]);
        }

        return [
            'enabled' => $enabled,
            'default_incoming' => in_array($defaultIncoming, ['allow', 'deny', 'reject'], true) ? $defaultIncoming : 'deny',
            'default_outgoing' => in_array($defaultOutgoing, ['allow', 'deny', 'reject'], true) ? $defaultOutgoing : 'allow',
            'allowed_ports' => collect($ports)->unique()->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detectSshFromServer(): array
    {
        $configPath = '/etc/ssh/sshd_config';
        if (! is_file($configPath) || ! is_readable($configPath)) {
            return [];
        }

        $contents = @file_get_contents($configPath);
        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $port = $this->parseSshConfigValue($contents, 'Port', '22');
        $passwordAuth = $this->parseSshConfigValue($contents, 'PasswordAuthentication', 'Off');
        $rootLogin = $this->parseSshConfigValue($contents, 'PermitRootLogin', 'no');
        $pubkeyAuth = $this->parseSshConfigValue($contents, 'PubkeyAuthentication', 'On');

        return [
            'port' => max(1, min(65535, (int) $port)),
            'password_authentication' => $this->normalizeOnOff($passwordAuth),
            'permit_root_login' => in_array($rootLogin, ['yes', 'no', 'prohibit-password', 'forced-commands-only'], true) ? $rootLogin : 'prohibit-password',
            'pubkey_authentication' => $this->normalizeOnOff($pubkeyAuth),
        ];
    }

    private function parseSshConfigValue(string $contents, string $key, string $fallback): string
    {
        $pattern = '/^\s*'.preg_quote($key, '/').'\s+([^\s#]+)\s*$/mi';
        if (preg_match($pattern, $contents, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return $fallback;
    }

    private function normalizeOnOff(string $value): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['yes', 'on', '1', 'true'], true) ? 'On' : 'Off';
    }

    /** @return array<string, mixed> */
    private function liveSecurityState(): array
    {
        $effective = $this->runCommand('sshd -T');
        $config = $this->detectSshFromServer();
        $config['port'] = (int) $this->parseSshConfigValue($effective, 'port', (string) ($config['port'] ?? 22));
        $config['password_authentication'] = $this->normalizeOnOff($this->parseSshConfigValue($effective, 'passwordauthentication', (string) ($config['password_authentication'] ?? 'Off')));
        $config['permit_root_login'] = $this->parseSshConfigValue($effective, 'permitrootlogin', (string) ($config['permit_root_login'] ?? 'no'));
        $config['pubkey_authentication'] = $this->normalizeOnOff($this->parseSshConfigValue($effective, 'pubkeyauthentication', (string) ($config['pubkey_authentication'] ?? 'On')));

        $serviceActive = trim($this->runCommand('systemctl is-active ssh')) === 'active';
        $serviceEnabled = trim($this->runCommand('systemctl is-enabled ssh')) === 'enabled';
        $listeners = [];
        foreach (preg_split('/\r\n|\r|\n/', $this->runCommand('ss -ltnH')) ?: [] as $line) {
            if (preg_match('/\s(\[[^]]+\]|[^\s:]+):(\d+)\s/', $line.' ', $matches) === 1 && (int) $matches[2] === (int) $config['port']) {
                $listeners[] = trim($matches[1], '[]');
            }
        }

        $allowedIps = [];
        $ufw = $this->runCommand('ufw status');
        foreach (preg_split('/\r\n|\r|\n/', $ufw) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote((string) $config['port'], '/').'(?:\/tcp)?\s+ALLOW\s+(.+)$/i', $line, $matches) === 1) {
                $source = trim((string) $matches[1]);
                if ($source !== '') {
                    $allowedIps[] = $source;
                }
            }
        }

        return [
            'checked_at' => now()->toIso8601String(),
            'ssh' => array_merge($config, [
                'service_active' => $serviceActive,
                'service_enabled' => $serviceEnabled,
                'listening' => count($listeners) > 0,
                'listen_addresses' => array_values(array_unique($listeners)),
                'config_valid' => $this->runCommand('sshd -t; echo $?') === "0\n",
            ]),
            'firewall' => [
                'enabled' => str_contains(strtolower($ufw), 'status: active'),
                'ssh_allowed_ips' => array_values(array_unique($allowedIps)),
            ],
        ];
    }

    /** @param array<string, mixed> $settings */
    private function applySshConfiguration(array $settings): ?string
    {
        $contents = implode("\n", [
            '# Managed by dPanel. Changes made here may be overwritten.',
            'Port '.(int) $settings['port'],
            'PasswordAuthentication '.($settings['password_authentication'] === 'On' ? 'yes' : 'no'),
            'PermitRootLogin '.$settings['permit_root_login'],
            'PubkeyAuthentication '.($settings['pubkey_authentication'] === 'On' ? 'yes' : 'no'),
            '',
        ]);
        $temporary = tempnam(sys_get_temp_dir(), 'dpanel-sshd-');
        if ($temporary === false || file_put_contents($temporary, $contents) === false) {
            return 'Unable to prepare the SSH configuration.';
        }

        try {
            $mkdir = $this->runPrivileged(['mkdir', '-p', '/etc/ssh/sshd_config.d']);
            if (! $mkdir->successful()) {
                return $this->commandError($mkdir, 'Unable to access the SSH configuration directory.');
            }
            $install = $this->runPrivileged(['install', '-m', '0644', $temporary, '/etc/ssh/sshd_config.d/99-dpanel.conf']);
            if (! $install->successful()) {
                return $this->commandError($install, 'Unable to write the SSH configuration.');
            }
            $test = $this->runPrivileged(['sshd', '-t']);
            if (! $test->successful()) {
                $this->runPrivileged(['rm', '-f', '/etc/ssh/sshd_config.d/99-dpanel.conf']);
                return $this->commandError($test, 'SSH rejected the new configuration; it was removed.');
            }
            $reload = $this->runPrivileged(['systemctl', 'reload', 'ssh']);
            if (! $reload->successful()) {
                return $this->commandError($reload, 'Configuration saved, but SSH could not be reloaded.');
            }
        } finally {
            @unlink($temporary);
        }

        return null;
    }

    private function runPrivileged(array $command)
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return Process::timeout(30)->run($command);
        }

        return Process::timeout(30)->run(array_merge(['sudo', '-n'], $command));
    }

    private function commandError($result, string $fallback): string
    {
        $error = trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output());

        return $error !== '' ? $error : $fallback;
    }

    private function runCommand(string $command): string
    {
        try {
            $output = shell_exec($command.' 2>&1');

            return is_string($output) ? $output : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
