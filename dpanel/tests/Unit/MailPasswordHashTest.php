<?php

namespace Tests\Unit;

use App\Support\MailPasswordHash;
use PHPUnit\Framework\TestCase;

class MailPasswordHashTest extends TestCase
{
    public function test_made_hash_verifies_only_the_same_password(): void
    {
        $hash = MailPasswordHash::make('S3cret:pw$');

        $this->assertStringStartsWith('{SHA512-CRYPT}$6$', $hash);
        $this->assertTrue(MailPasswordHash::verify('S3cret:pw$', $hash));
        $this->assertFalse(MailPasswordHash::verify('other', $hash));
    }

    public function test_unprefixed_crypt_hash_from_openssl_verifies(): void
    {
        $hash = crypt('S3cret', '$6$abcdefgh12345678$');

        $this->assertTrue(MailPasswordHash::verify('S3cret', $hash));
        $this->assertTrue(MailPasswordHash::isHash($hash));
    }

    public function test_hash_of_a_hash_does_not_verify(): void
    {
        $hash = crypt('S3cret', '$6$abcdefgh12345678$');
        $doubleHashed = MailPasswordHash::make($hash);

        $this->assertFalse(MailPasswordHash::verify('S3cret', $doubleHashed));
    }

    public function test_plain_and_empty_values(): void
    {
        $this->assertTrue(MailPasswordHash::verify('abc', '{PLAIN}abc'));
        $this->assertFalse(MailPasswordHash::verify('abc', ''));
        $this->assertFalse(MailPasswordHash::verify('abc', 'abc'));
        $this->assertFalse(MailPasswordHash::isHash('abc'));
    }
}
