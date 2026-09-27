<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Remembers where a user was inside the panel when their session ended
 * (explicit logout, inactivity expiry, or an old cpsess link) so the next
 * login lands back on that page instead of the dashboard.
 *
 * Only the part after "/cpsess{token}" is stored: the token rotates on every
 * login, so it is re-prefixed with the new token when consumed. Query strings
 * are dropped because they can carry one-time tokens or other secrets.
 */
class PanelReturnPath
{
    private const KEY = 'panel.last_path';
    private const OWNER_KEY = 'panel.last_path_user';
    private const MAX_LENGTH = 2000;
    private const IGNORED = ['/logout', '/login'];

    /** Remember the page of the current request (panel page visits only). */
    public static function rememberRequest(Request $request, ?int $userId = null): void
    {
        // Form posts and background JSON fetches are not pages to return to.
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return;
        }

        self::remember($request, '/'.ltrim($request->path(), '/'), $userId);
    }

    /** Remember the page a same-host URL (e.g. the Referer) points to. */
    public static function rememberUrl(Request $request, string $url, ?int $userId = null): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($host) || ! is_string($path) || ! hash_equals(strtolower($request->getHost()), strtolower($host))) {
            return;
        }

        self::remember($request, $path, $userId);
    }

    /**
     * The URL to send a freshly logged-in user to: their remembered page when
     * it belongs to them (or to nobody in particular), otherwise the dashboard.
     */
    public static function consume(Request $request, string $token, ?int $userId): string
    {
        $path = $request->session()->pull(self::KEY);
        $owner = $request->session()->pull(self::OWNER_KEY);
        // A different account signing in on the same browser starts fresh.
        $ownerMatches = $owner === null || $userId === null || (int) $owner === $userId;

        if ($token !== '' && $ownerMatches && is_string($path) && self::isSafeRelative($path)) {
            return '/cpsess'.$token.$path;
        }

        return route('dashboard', ['token' => $token], absolute: false);
    }

    private static function remember(Request $request, string $fullPath, ?int $userId): void
    {
        if (! $request->hasSession()
            || preg_match('#\A/cpsess[^/]+(/.*)\z#', $fullPath, $match) !== 1) {
            return;
        }

        $relative = $match[1];
        if (in_array($relative, self::IGNORED, true) || strlen($relative) > self::MAX_LENGTH || ! self::isSafeRelative($relative)) {
            return;
        }

        $request->session()->put(self::KEY, $relative);
        $request->session()->put(self::OWNER_KEY, $userId);
    }

    private static function isSafeRelative(string $path): bool
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\');
    }
}
