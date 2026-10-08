<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MailSettings
{
    private const TABLE = 'mail_settings';
    private const STATE_KEY = 'state';

    /**
     * @return array{outbound_gate: bool}
     */
    public function read(): array
    {
        $defaults = $this->defaults();

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return $defaults;
            }

            $decoded = json_decode((string) DB::table(self::TABLE)
                ->where('setting_key', self::STATE_KEY)
                ->value('setting_value'), true);

            return is_array($decoded)
                ? ['outbound_gate' => (bool) ($decoded['outbound_gate'] ?? $defaults['outbound_gate'])]
                : $defaults;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * @param  array{outbound_gate?: bool}  $state
     */
    public function write(array $state): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['setting_key' => self::STATE_KEY],
            [
                'setting_value' => json_encode(array_merge($this->read(), $state), JSON_PRETTY_PRINT),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * @return array{outbound_gate: bool}
     */
    private function defaults(): array
    {
        return [
            // Off until an admin turns it on: holding mail is a choice, not a default.
            'outbound_gate' => false,
        ];
    }
}
