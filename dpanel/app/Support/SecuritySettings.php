<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SecuritySettings
{
    private const TABLE = 'security_settings';
    private const STATE_KEY = 'state';

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $defaults = $this->defaults();

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return $defaults;
            }

            $raw = DB::table(self::TABLE)
                ->where('setting_key', self::STATE_KEY)
                ->value('setting_value');

            if (! is_string($raw) || trim($raw) === '') {
                return $defaults;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return $defaults;
            }

            $twoFactor = array_merge($defaults['two_factor'], (array) ($decoded['two_factor'] ?? []));
            $twoFactor['enabled'] = true;
            $twoFactor['email'] = true;
            $twoFactor['enforce_admin'] = true;
            $twoFactor['enforce_reseller'] = true;

            return [
                'firewall' => array_merge($defaults['firewall'], (array) ($decoded['firewall'] ?? [])),
                'ssh' => array_merge($defaults['ssh'], (array) ($decoded['ssh'] ?? [])),
                'telegram' => array_merge($defaults['telegram'], (array) ($decoded['telegram'] ?? [])),
                'two_factor' => $twoFactor,
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    public function write(array $state): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $normalized = [
            'firewall' => [
                'enabled' => (bool) ($state['firewall']['enabled'] ?? false),
                'default_incoming' => (string) ($state['firewall']['default_incoming'] ?? 'deny'),
                'default_outgoing' => (string) ($state['firewall']['default_outgoing'] ?? 'allow'),
                'allowed_ports' => array_values(array_unique(array_map('intval', (array) ($state['firewall']['allowed_ports'] ?? [22, 80, 443])))),
            ],
            'ssh' => [
                'port' => max(1, (int) ($state['ssh']['port'] ?? 22)),
                'password_authentication' => (string) ($state['ssh']['password_authentication'] ?? 'Off'),
                'permit_root_login' => (string) ($state['ssh']['permit_root_login'] ?? 'no'),
                'pubkey_authentication' => (string) ($state['ssh']['pubkey_authentication'] ?? 'On'),
            ],
            'telegram' => [
                'enabled' => (bool) ($state['telegram']['enabled'] ?? false),
                'bot_token' => trim((string) ($state['telegram']['bot_token'] ?? '')),
                'chat_id' => trim((string) ($state['telegram']['chat_id'] ?? '')),
                'message' => trim((string) ($state['telegram']['message'] ?? 'Security alert from ServerPanel')),
            ],
            'two_factor' => [
                'enabled' => true,
                'email' => true,
                'telegram' => (bool) ($state['two_factor']['telegram'] ?? false),
                'google_auth_app' => (bool) ($state['two_factor']['google_auth_app'] ?? true),
                'code_ttl_minutes' => max(1, (int) ($state['two_factor']['code_ttl_minutes'] ?? 10)),
                'enforce_admin' => true,
                'enforce_reseller' => true,
            ],
        ];

        DB::table(self::TABLE)->updateOrInsert(
            ['setting_key' => self::STATE_KEY],
            [
                'setting_value' => json_encode($normalized, JSON_PRETTY_PRINT),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'firewall' => [
                'enabled' => false,
                'default_incoming' => 'deny',
                'default_outgoing' => 'allow',
                'allowed_ports' => [22, 80, 443],
            ],
            'ssh' => [
                'port' => 22,
                'password_authentication' => 'Off',
                'permit_root_login' => 'no',
                'pubkey_authentication' => 'On',
            ],
            'telegram' => [
                'enabled' => false,
                'bot_token' => '',
                'chat_id' => '',
                'message' => 'Security alert from ServerPanel',
            ],
            'two_factor' => [
                'enabled' => true,
                'email' => true,
                'telegram' => false,
                'google_auth_app' => true,
                'code_ttl_minutes' => 10,
                'enforce_admin' => true,
                'enforce_reseller' => true,
            ],
        ];
    }
}
