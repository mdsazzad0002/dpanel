<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteEdgeCache extends Model
{
    public const MODES = ['off', 'standard', 'everything'];

    /** Login, admin and checkout pages are personal; never cache them. */
    public const DEFAULT_BYPASS_PATHS = [
        '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php', '/wp-cron.php',
        '/cart', '/checkout', '/my-account',
        '/admin', '/login', '/register', '/logout', '/api',
    ];

    /** Cookies that mark a signed-in visitor or a filled cart. */
    public const DEFAULT_BYPASS_COOKIES = [
        'wordpress_logged_in_', 'wp-postpass_', 'comment_author_',
        'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_',
    ];

    /** Cloudflare's development mode lasts three hours too. */
    public const DEVELOPMENT_MODE_SECONDS = 3 * 3600;

    protected $table = 'website_edge_cache';

    protected $fillable = [
        'website_id',
        'mode',
        'edge_ttl',
        'browser_ttl',
        'bypass_paths',
        'bypass_cookies',
        'ignore_query_string',
        'serve_stale',
        'development_mode_until',
    ];

    protected $casts = [
        'edge_ttl' => 'integer',
        'browser_ttl' => 'integer',
        'ignore_query_string' => 'boolean',
        'serve_stale' => 'boolean',
        'development_mode_until' => 'integer',
    ];

    public static function forWebsite(string $websiteId): self
    {
        return self::query()->firstOrNew(['website_id' => $websiteId], [
            'mode' => 'off',
            'edge_ttl' => 3600,
            'browser_ttl' => null,
            'bypass_paths' => implode("\n", self::DEFAULT_BYPASS_PATHS),
            'bypass_cookies' => implode("\n", self::DEFAULT_BYPASS_COOKIES),
            'ignore_query_string' => false,
            'serve_stale' => true,
        ]);
    }

    public function developmentModeActive(): bool
    {
        return (int) $this->development_mode_until > time();
    }

    /** @return array<string, mixed> */
    public function toSettings(): array
    {
        return [
            'mode' => $this->mode,
            'edge_ttl' => $this->edge_ttl,
            'browser_ttl' => $this->browser_ttl,
            'bypass_paths' => (string) $this->bypass_paths,
            'bypass_cookies' => (string) $this->bypass_cookies,
            'ignore_query_string' => $this->ignore_query_string,
            'serve_stale' => $this->serve_stale,
            'development_mode_until' => $this->developmentModeActive() ? (int) $this->development_mode_until : null,
        ];
    }
}
