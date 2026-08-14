<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWhmcsRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('whmcs.allowed_ips', []);
        $allowedDomains = config('whmcs.allowed_domains', []);
        $clientId = trim((string) config('whmcs.client_id'));
        $secret = (string) config('whmcs.secret');
        if ($clientId === '' || strlen($secret) < 32 || ! is_array($allowed) || $allowed === []
            || ! is_array($allowedDomains) || $allowedDomains === []) {
            return $this->deny('WHMCS integration is not configured.', 503);
        }
        if (! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return $this->deny('Source IP is not allowed.', 403);
        }

        $providedClient = trim((string) $request->header('X-DPanel-Client'));
        $providedDomain = $this->normalizeDomain((string) $request->header('X-WHMCS-Domain'));
        $timestamp = trim((string) $request->header('X-DPanel-Timestamp'));
        $nonce = trim((string) $request->header('X-DPanel-Nonce'));
        $signature = strtolower(trim((string) $request->header('X-DPanel-Signature')));
        if (! hash_equals($clientId, $providedClient) || $providedDomain === ''
            || ! in_array($providedDomain, $allowedDomains, true) || ! ctype_digit($timestamp)
            || ! preg_match('/^[A-Za-z0-9_-]{16,100}$/', $nonce) || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->deny('Invalid authentication headers.', 401);
        }
        $tolerance = max(30, (int) config('whmcs.timestamp_tolerance', 300));
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->deny('Request timestamp expired.', 401);
        }

        $canonical = implode("\n", [
            strtoupper($request->method()), $request->getPathInfo(), $providedDomain, $timestamp, $nonce,
            hash('sha256', (string) $request->getContent()),
        ]);
        if (! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return $this->deny('Invalid request signature.', 401);
        }
        if (! Cache::add('whmcs:nonce:'.hash('sha256', $clientId.'|'.$nonce), true, $tolerance * 2)) {
            return $this->deny('Request replay rejected.', 409);
        }
        $request->attributes->set('whmcs_request_id', $nonce);
        $request->attributes->set('whmcs_domain', $providedDomain);
        return $next($request);
    }

    private function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || str_contains($value, '/') || str_contains($value, ':')) {
            return '';
        }

        return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $value : '';
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['ok' => false, 'message' => $message], $status)->header('Cache-Control', 'no-store');
    }
}
