<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    private const SETTINGS_TABLE = 'security_settings';
    private const STATE_KEY = 'state';

    /**
     * @var array<string, mixed>
     */
    private const DEFAULT_STATE = [
        'firewall' => [
            'enabled' => false,
            'default_incoming' => 'deny',
            'default_outgoing' => 'allow',
            'allowed_ports' => [22, 80, 443],
        ],
        'ssh' => [
            'port' => 22,
            'password_authentication' => 'Off',
            'permit_root_login' => 'prohibit-password',
            'pubkey_authentication' => 'On',
        ],
    ];

    public function manager(): Response
    {
        $state = $this->readState();

        return Inertia::render('SecurityManager', [
            'firewall' => $state['firewall'],
            'ssh' => $state['ssh'],
        ]);
    }

    public function syncFromServer(Request $request): RedirectResponse|JsonResponse
    {
        $state = $this->readState();

        $detectedFirewall = $this->detectFirewallFromServer();
        if (count($detectedFirewall) > 0) {
            $state['firewall'] = array_merge($state['firewall'], $detectedFirewall);
        }

        $detectedSsh = $this->detectSshFromServer();
        if (count($detectedSsh) > 0) {
            $state['ssh'] = array_merge($state['ssh'], $detectedSsh);
        }

        $this->writeState($state);

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

        $state = $this->readState();
        $state['firewall'] = [
            'enabled' => (bool) $validated['enabled'],
            'default_incoming' => (string) $validated['default_incoming'],
            'default_outgoing' => (string) $validated['default_outgoing'],
            'allowed_ports' => collect($validated['allowed_ports'] ?? [])->unique()->values()->all(),
        ];
        $this->writeState($state);

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

        $state = $this->readState();
        $state['ssh'] = [
            'port' => (int) $validated['port'],
            'password_authentication' => (string) $validated['password_authentication'],
            'permit_root_login' => (string) $validated['permit_root_login'],
            'pubkey_authentication' => (string) $validated['pubkey_authentication'],
        ];
        $this->writeState($state);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'SSH settings updated.',
                'data' => ['ssh' => $state['ssh']],
            ]);
        }

        return redirect()->route('security.manager')->with('success', 'SSH settings updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        if (! DB::getSchemaBuilder()->hasTable(self::SETTINGS_TABLE)) {
            return self::DEFAULT_STATE;
        }

        $row = DB::table(self::SETTINGS_TABLE)->where('setting_key', self::STATE_KEY)->first();
        if ($row === null || ! isset($row->setting_value)) {
            return self::DEFAULT_STATE;
        }

        $decoded = json_decode((string) $row->setting_value, true);
        if (! is_array($decoded)) {
            return self::DEFAULT_STATE;
        }

        return [
            'firewall' => array_merge(self::DEFAULT_STATE['firewall'], $decoded['firewall'] ?? []),
            'ssh' => array_merge(self::DEFAULT_STATE['ssh'], $decoded['ssh'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        if (! DB::getSchemaBuilder()->hasTable(self::SETTINGS_TABLE)) {
            return;
        }

        DB::table(self::SETTINGS_TABLE)->updateOrInsert(
            ['setting_key' => self::STATE_KEY],
            [
                'setting_value' => json_encode($state, JSON_PRETTY_PRINT),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
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
        $rootLogin = $this->parseSshConfigValue($contents, 'PermitRootLogin', 'prohibit-password');
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
