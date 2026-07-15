<?php

namespace App\Http\Controllers;

use App\Support\SecuritySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function __construct(private readonly SecuritySettings $settings)
    {
    }

    public function manager(): Response
    {
        $state = $this->settings->read();

        return Inertia::render('SecurityManager', [
            'firewall' => $state['firewall'],
            'ssh' => $state['ssh'],
            'telegram' => $state['telegram'],
            'twoFactor' => $state['two_factor'],
            'telegramBotConfigured' => trim((string) config('services.telegram.bot_token', '')) !== '',
            'telegramBotUsername' => trim((string) config('services.telegram.bot_username', '')),
        ]);
    }

    public function syncFromServer(Request $request): RedirectResponse|JsonResponse
    {
        $state = $this->settings->read();

        $detectedFirewall = $this->detectFirewallFromServer();
        if (count($detectedFirewall) > 0) {
            $state['firewall'] = array_merge($state['firewall'], $detectedFirewall);
        }

        $detectedSsh = $this->detectSshFromServer();
        if (count($detectedSsh) > 0) {
            $state['ssh'] = array_merge($state['ssh'], $detectedSsh);
        }

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

    public function updateFirewall(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_incoming' => ['required', 'in:allow,deny,reject'],
            'default_outgoing' => ['required', 'in:allow,deny,reject'],
            'allowed_ports' => ['nullable', 'array'],
            'allowed_ports.*' => ['integer', 'min:1', 'max:65535'],
        ]);

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
                'data' => ['firewall' => $state['firewall']],
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
                'data' => ['ssh' => $state['ssh']],
            ]);
        }

        return redirect()->route('security.manager')->with('success', 'SSH settings updated.');
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
