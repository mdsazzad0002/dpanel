<?php

namespace App\Services\Seo;

use App\Models\Website;

/**
 * The folder the edge gateway serves a website's static files from, and
 * whether the panel can write files there for the SEO tools.
 */
class WebsiteDocumentRoot
{
    /**
     * @param  string  $what  what would be written, for the reason text (e.g. "robots.txt")
     * @return array{writable: bool, reason: string|null, root: string|null}
     */
    public static function resolve(Website $website, string $what): array
    {
        $runtime = strtolower((string) ($website->runtime ?: 'php'));
        if (in_array($runtime, ['node', 'python', 'docker'], true)) {
            return self::readOnly("Every request to this {$runtime} site goes to the app itself, so {$what} has to be served by the app.");
        }
        if (trim((string) $website->site_owner) === '') {
            return self::readOnly('This website has no owner account to write files as.');
        }

        // Same order as the gateway: root_path/start_directory, then root_path.
        $root = rtrim(str_replace('\\', '/', trim((string) $website->root_path)), '/');
        if ($root === '' || str_contains($root, '..')) {
            return self::readOnly('The website folder was not found on this server.');
        }
        $start = trim(str_replace('\\', '/', (string) $website->start_directory), '/');
        if ($start !== '' && $start !== '.' && ! str_contains($start, '..') && is_dir("{$root}/{$start}")) {
            return ['writable' => true, 'reason' => null, 'root' => "{$root}/{$start}"];
        }

        return is_dir($root)
            ? ['writable' => true, 'reason' => null, 'root' => $root]
            : self::readOnly('The website folder was not found on this server.');
    }

    /** @return array{writable: false, reason: string, root: null} */
    private static function readOnly(string $reason): array
    {
        return ['writable' => false, 'reason' => $reason, 'root' => null];
    }
}
