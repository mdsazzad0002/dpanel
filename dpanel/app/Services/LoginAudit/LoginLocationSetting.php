<?php

namespace App\Services\LoginAudit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the login form asks the browser for its location, set separately
 * for HTTPS and plain HTTP:
 *   off      - never asks
 *   ask      - asks; signing in still works if no location comes back
 *   required - no location means no login
 * Most browsers give a location only on HTTPS (and localhost), so "required"
 * can be saved for a scheme only after a test on that scheme worked.
 * `php artisan login-audit:location off` turns both off if an admin is locked out.
 */
class LoginLocationSetting
{
    public const MODES = ['off', 'ask', 'required'];
    public const SCHEMES = ['https', 'http'];
    public const DEFAULT = 'ask';

    private const TABLE = 'security_settings';
    // The https key predates the http one; it keeps its name.
    private const KEYS = ['https' => 'login_location_mode', 'http' => 'login_location_mode_http'];

    /** @var array<string, string> */
    private array $cached = [];

    public function mode(string $scheme = 'https'): string
    {
        $scheme = $scheme === 'http' ? 'http' : 'https';
        if (isset($this->cached[$scheme])) {
            return $this->cached[$scheme];
        }

        try {
            $value = Schema::hasTable(self::TABLE)
                ? DB::table(self::TABLE)->where('setting_key', self::KEYS[$scheme])->value('setting_value')
                : null;
        } catch (\Throwable) {
            $value = null;
        }

        return $this->cached[$scheme] = in_array($value, self::MODES, true) ? $value : self::DEFAULT;
    }

    /** @return array{https: string, http: string} */
    public function all(): array
    {
        return ['https' => $this->mode('https'), 'http' => $this->mode('http')];
    }

    public function set(string $scheme, string $mode): void
    {
        if (! in_array($scheme, self::SCHEMES, true)) {
            throw new \InvalidArgumentException("Unknown scheme {$scheme}; use https or http.");
        }
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Unknown mode {$mode}; use off, ask or required.");
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['setting_key' => self::KEYS[$scheme]],
            ['setting_value' => $mode, 'updated_at' => now(), 'created_at' => now()],
        );
        $this->cached[$scheme] = $mode;
    }
}
