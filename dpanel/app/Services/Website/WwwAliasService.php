<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Services\Ssl\SslLifecycleService;
use Carbon\Carbon;

/**
 * The www.<domain> alias of a main website. It is not a separate website
 * row: the main website's include_www flag serves it, and the main
 * website's certificate covers it, so its state is read from both.
 */
class WwwAliasService
{
    /** @return array<string, mixed>|null null when the website cannot have a www alias */
    public function describe(Website $website): ?array
    {
        $domain = strtolower((string) $website->domain);
        if ((string) $website->type !== 'primary'
            || str_starts_with($domain, 'www.')
            || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $wwwDomain = "www.{$domain}";

        return [
            'domain' => $wwwDomain,
            'enabled' => (bool) ($website->include_www ?? true),
            'ssl_enabled' => (bool) $website->enable_ssl,
            'ssl_available' => ! SslLifecycleService::isReservedSslDomain($wwwDomain),
            'resolves' => gethostbynamel($wwwDomain) !== false,
        ];
    }

    /**
     * Whether the certificate this server serves for www.<domain> covers it.
     *
     * @return array{covered: bool, valid_to: ?string, days_remaining: ?int}
     */
    public function certificate(string $wwwDomain): array
    {
        $none = ['covered' => false, 'valid_to' => null, 'days_remaining' => null];
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $wwwDomain,
        ]]);
        $client = @stream_socket_client('ssl://127.0.0.1:443', $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $context);
        if (! is_resource($client)) {
            return $none;
        }
        $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        @fclose($client);
        $parsed = $certificate ? @openssl_x509_parse($certificate) : false;
        if (! is_array($parsed)) {
            return $none;
        }

        $names = array_map(
            fn (string $entry): string => strtolower(trim(substr(trim($entry), 4))),
            array_filter(
                explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')),
                fn (string $entry): bool => str_starts_with(trim($entry), 'DNS:'),
            ),
        );
        $validTo = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;

        return [
            'covered' => in_array($wwwDomain, $names, true) && $validTo !== null && $validTo > time(),
            'valid_to' => $validTo !== null ? Carbon::createFromTimestamp($validTo)->toIso8601String() : null,
            'days_remaining' => $validTo !== null ? (int) floor(($validTo - time()) / 86400) : null,
        ];
    }
}
