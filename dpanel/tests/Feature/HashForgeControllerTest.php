<?php

namespace Tests\Feature;

use App\Http\Controllers\Tools\HashForgeController;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HashForgeControllerTest extends TestCase
{
    private function hit(string $method, array $input): array
    {
        $response = (new HashForgeController)->{$method}(Request::create('/', 'POST', $input));

        return ['status' => $response->getStatusCode(), 'data' => $response->getData(true)];
    }

    public function test_generates_known_digests(): void
    {
        $out = $this->hit('generate', ['text' => 'password', 'digests' => ['md5', 'sha256', 'bogus']]);

        $this->assertSame(200, $out['status']);
        $this->assertSame(['md5', 'sha256'], array_column($out['data']['results'], 'id'));
        $this->assertSame('5f4dcc3b5aa765d61d8327deb882cf99', $out['data']['results'][0]['value']);
        $this->assertSame(128, $out['data']['results'][0]['bits']);
    }

    public function test_uppercase_and_hmac(): void
    {
        $out = $this->hit('generate', ['text' => 'abc', 'digests' => ['sha256'], 'hmac_key' => 'k', 'uppercase' => true]);

        $row = $out['data']['results'][0];
        $this->assertSame('HMAC-SHA-256', $row['label']);
        $this->assertSame(strtoupper(hash_hmac('sha256', 'abc', 'k')), $row['value']);
    }

    public function test_password_hashes_verify(): void
    {
        $out = $this->hit('generate', ['text' => 's3cret', 'passwords' => ['bcrypt', 'sha512crypt', 'md5crypt'], 'bcrypt_cost' => 4]);

        foreach ($out['data']['results'] as $row) {
            $check = $this->hit('verify', ['text' => 's3cret', 'hash' => $row['value']]);
            $this->assertTrue($check['data']['match'], $row['id']);
            $this->assertFalse($this->hit('verify', ['text' => 'wrong', 'hash' => $row['value']])['data']['match'], $row['id']);
        }
    }

    public function test_bcrypt_truncation_is_flagged(): void
    {
        $out = $this->hit('generate', ['text' => str_repeat('a', 80), 'passwords' => ['bcrypt'], 'bcrypt_cost' => 4]);

        $this->assertTrue($out['data']['bcrypt_truncated']);
    }

    public function test_verify_unsalted_digest_by_length(): void
    {
        $match = $this->hit('verify', ['text' => 'password', 'hash' => '5F4DCC3B5AA765D61D8327DEB882CF99']);
        $this->assertTrue($match['data']['match']);
        $this->assertSame('MD5', $match['data']['algorithm']);

        $miss = $this->hit('verify', ['text' => 'nope', 'hash' => hash('sha256', 'x')]);
        $this->assertFalse($miss['data']['match']);
        $this->assertContains('SHA-256', $miss['data']['candidates']);

        $junk = $this->hit('verify', ['text' => 'x', 'hash' => 'not-a-hash']);
        $this->assertTrue($junk['data']['unknown']);
    }

    public function test_rejects_out_of_range_bcrypt_cost(): void
    {
        $this->expectException(ValidationException::class);
        $this->hit('generate', ['text' => 'x', 'passwords' => ['bcrypt'], 'bcrypt_cost' => 20]);
    }

    public function test_identify_reports_candidates_and_salted_formats(): void
    {
        $byLength = $this->hit('identify', ['hash' => hash('sha256', 'x')]);
        $this->assertFalse($byLength['data']['salted']);
        $this->assertContains('SHA-256', $byLength['data']['candidates']);

        $bcrypt = $this->hit('identify', ['hash' => password_hash('x', PASSWORD_BCRYPT)]);
        $this->assertTrue($bcrypt['data']['salted']);

        $junk = $this->hit('identify', ['hash' => 'xyz']);
        $this->assertSame('unknown', $junk['data']['note']);
    }

    public function test_crack_finds_password_from_a_builtin_set(): void
    {
        $found = $this->hit('crack', ['hash' => hash('md5', 'password'), 'sets' => ['common']]);
        $this->assertTrue($found['data']['found']);
        $this->assertSame('password', $found['data']['plaintext']);

        // A name only lives in the names set, so it needs that set selected.
        $missWithoutNames = $this->hit('crack', ['hash' => hash('md5', 'michael'), 'sets' => ['keyboard']]);
        $this->assertFalse($missWithoutNames['data']['found']);
        $withNames = $this->hit('crack', ['hash' => hash('md5', 'michael'), 'sets' => ['names']]);
        $this->assertTrue($withNames['data']['found']);
    }

    public function test_crack_brute_forces_a_short_pin(): void
    {
        $out = $this->hit('crack', ['hash' => hash('sha1', '4821'), 'brute' => true, 'brute_charset' => ['digits'], 'brute_min' => 4, 'brute_max' => 4]);
        $this->assertTrue($out['data']['found']);
        $this->assertSame('4821', $out['data']['plaintext']);
    }

    public function test_crack_rejects_an_oversized_brute_range(): void
    {
        $out = $this->hit('crack', ['hash' => hash('md5', 'x'), 'brute' => true, 'brute_charset' => ['lower'], 'brute_min' => 1, 'brute_max' => 6]);
        $this->assertSame(422, $out['status']);
    }

    public function test_crack_can_restrict_to_chosen_algorithms(): void
    {
        // md5 and md4 share a length; limiting to md5 still matches an md5 hash...
        $hit = $this->hit('crack', ['hash' => hash('md5', 'password'), 'sets' => ['common'], 'algos' => ['md5']]);
        $this->assertTrue($hit['data']['found']);

        // ...but limiting to the wrong same-length algorithm finds nothing.
        $miss = $this->hit('crack', ['hash' => hash('md5', 'password'), 'sets' => ['common'], 'algos' => ['md4']]);
        $this->assertFalse($miss['data']['found']);
    }

    public function test_crack_brute_forces_with_custom_characters(): void
    {
        // No character class selected — only the custom set should be used.
        $out = $this->hit('crack', ['hash' => hash('sha1', 'a.b'), 'brute' => true, 'brute_charset' => [], 'brute_custom' => 'ab.', 'brute_min' => 3, 'brute_max' => 3]);
        $this->assertTrue($out['data']['found']);
        $this->assertSame('a.b', $out['data']['plaintext']);
    }

    public function test_crack_uses_custom_wordlist_and_salted_hashes(): void
    {
        $hash = password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]);
        $out = $this->hit('crack', ['hash' => $hash, 'wordlist' => "alpha\nhunter2\nbeta"]);
        $this->assertTrue($out['data']['found']);
        $this->assertSame('hunter2', $out['data']['plaintext']);

        $miss = $this->hit('crack', ['hash' => hash('sha256', 'zzz-not-listed'), 'wordlist' => "a\nb"]);
        $this->assertFalse($miss['data']['found']);
    }

    public function test_crack_requires_a_source(): void
    {
        $out = $this->hit('crack', ['hash' => hash('md5', 'x')]);
        $this->assertSame(422, $out['status']);
    }

    public function test_audit_flags_common_and_username_passwords(): void
    {
        $common = $this->hit('audit', ['password' => 'password']);
        $this->assertTrue($common['data']['guessable']);
        $this->assertSame(0, $common['data']['score']);
        $this->assertContains('in_common_list', $common['data']['reasons']);

        $asUser = $this->hit('audit', ['password' => 'johnsmith', 'username' => 'johnsmith']);
        $this->assertContains('equals_username', $asUser['data']['reasons']);

        $strong = $this->hit('audit', ['password' => 'Tr0ub4dour&3xplr!Zx']);
        $this->assertFalse($strong['data']['guessable']);
        $this->assertGreaterThanOrEqual(3, $strong['data']['score']);
    }
}
