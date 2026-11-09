<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Upload-folder PHP, hotlink protection, Cloudflare-only (task S6) and the firewall mode (S2) of a website. */
class WebsiteGuardSetting extends Model
{
    protected $primaryKey = 'website_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['website_id', 'no_php_paths', 'hotlink_protection', 'hotlink_allowed_hosts', 'cloudflare_only', 'waf_mode'];

    protected $casts = ['hotlink_protection' => 'boolean', 'cloudflare_only' => 'boolean'];

    public const WAF_MODES = ['off', 'detect', 'block'];

    /** @return list<string> */
    public function noPhpPaths(): array
    {
        return self::split($this->no_php_paths);
    }

    /** @return list<string> */
    public function hotlinkAllowedHosts(): array
    {
        return self::split($this->hotlink_allowed_hosts);
    }

    /** @return list<string> */
    private static function split(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
