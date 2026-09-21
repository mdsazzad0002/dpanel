<?php

namespace App\Support;

/**
 * Guards against SSRF when the app makes an outbound request to a
 * user-supplied URL (e.g. a business's own "live data" API). Requires
 * https and rejects any URL whose host resolves to a loopback/private/
 * link-local/reserved IP — checked again at call time (not just at save
 * time) to guard against DNS rebinding.
 */
class SafeUrlValidator
{
    public static function isSafePublicUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! $parts || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
