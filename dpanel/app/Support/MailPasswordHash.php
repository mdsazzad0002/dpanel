<?php

namespace App\Support;

/**
 * Mailbox password hashes as Dovecot reads them from the mailboxes table
 * (default scheme SHA512-CRYPT). Hashing in PHP avoids doveadm, which the
 * web user often cannot run because Dovecot's auth config is root-only.
 */
class MailPasswordHash
{
    private const SALT_CHARS = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function make(string $password): string
    {
        $salt = '';
        for ($i = 0; $i < 16; $i++) {
            $salt .= self::SALT_CHARS[random_int(0, strlen(self::SALT_CHARS) - 1)];
        }

        return '{SHA512-CRYPT}'.crypt($password, '$6$'.$salt.'$');
    }

    /** True when Dovecot would accept $password against the stored $hash. */
    public static function verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        if (str_starts_with($hash, '{PLAIN}')) {
            return hash_equals(substr($hash, 7), $password);
        }

        $crypted = preg_replace('/^\{(SHA512-CRYPT|SHA256-CRYPT|CRYPT|BLF-CRYPT|MD5-CRYPT)\}/i', '', $hash);
        if (! str_starts_with((string) $crypted, '$')) {
            return false;
        }

        return hash_equals($crypted, crypt($password, $crypted));
    }

    /** A stored value Dovecot treats as a hash rather than a plaintext password. */
    public static function isHash(string $value): bool
    {
        return str_starts_with($value, '{') || str_starts_with($value, '$');
    }
}
