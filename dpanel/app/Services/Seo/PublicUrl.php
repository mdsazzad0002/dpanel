<?php

namespace App\Services\Seo;

use Illuminate\Validation\ValidationException;

/**
 * Parses an address typed into an SEO tool: a public http(s) host name on
 * the default port, with an optional path. The fetcher still refuses hosts
 * that resolve to private addresses.
 */
class PublicUrl
{
    /**
     * @return array{0: string, 1: bool, 2: string|null} host, https, path (with query) or null for the root
     *
     * @throws ValidationException
     */
    public static function parse(string $url, string $field = 'url'): array
    {
        $url = trim($url);
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = "https://{$url}";
        }
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $valid = in_array($scheme, ['http', 'https'], true)
            && ! isset($parts['user'])
            && ! isset($parts['port'])
            && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
        if (! $valid) {
            throw ValidationException::withMessages([$field => 'Enter a public http(s) address such as https://example.com or https://example.com/page, without a port.']);
        }

        $path = (string) ($parts['path'] ?? '');
        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        return [$host, $scheme === 'https', trim($path, '/') === '' ? null : $path];
    }
}
