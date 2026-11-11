<?php

namespace App\Services\Extensions;

use App\Models\Extension;
use App\Models\User;

/**
 * HMAC-SHA256 with the extension's secret (plans/extension-spec.md §6).
 * Webhooks sign "<timestamp>.<body>"; the admin page's context is
 * "<base64url json>.<base64url hmac>", which the extension checks itself.
 */
final class ExtensionSigner
{
    public const CONTEXT_TTL_SECONDS = 300;

    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Who is looking at the extension's admin page, for its server to trust. */
    public static function context(Extension $extension, User $viewer, string $locale, int $now): string
    {
        $payload = json_encode([
            'extension' => $extension->identifier,
            'user' => ['id' => $viewer->id, 'name' => $viewer->name, 'role' => $viewer->role],
            'locale' => $locale,
            'iat' => $now,
            'exp' => $now + self::CONTEXT_TTL_SECONDS,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = self::base64url($payload);

        return $encoded.'.'.self::base64url(hash_hmac('sha256', $encoded, (string) $extension->webhook_secret, true));
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
