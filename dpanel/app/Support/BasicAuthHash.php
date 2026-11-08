<?php

namespace App\Support;

/**
 * The password hash the edge gateway checks for a protected directory
 * (drust `edge_gateway/basic_auth.rs`): `s256i$<rounds>$<salt hex>$<hex>`,
 * with d0 = sha256(salt . password) and d(n+1) = sha256(d(n) . password).
 * The gateway remembers a header once it verified, so the rounds are paid
 * once per visitor, not per asset.
 */
final class BasicAuthHash
{
    public const ROUNDS = 20000;

    public static function make(string $password, int $rounds = self::ROUNDS): string
    {
        $salt = random_bytes(16);

        return sprintf('s256i$%d$%s$%s', $rounds, bin2hex($salt), bin2hex(self::digest($salt, $password, $rounds)));
    }

    public static function check(string $password, string $stored): bool
    {
        $parts = explode('$', $stored);
        if (count($parts) !== 4 || $parts[0] !== 's256i' || ! ctype_digit($parts[1]) || ! ctype_xdigit($parts[2])) {
            return false;
        }
        $salt = hex2bin($parts[2]);

        return $salt !== false && hash_equals(strtolower($parts[3]), bin2hex(self::digest($salt, $password, (int) $parts[1])));
    }

    private static function digest(string $salt, string $password, int $rounds): string
    {
        $digest = hash('sha256', $salt.$password, true);
        for ($i = 1; $i < $rounds; $i++) {
            $digest = hash('sha256', $digest.$password, true);
        }

        return $digest;
    }
}
