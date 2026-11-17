<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tools → Hash Forge. Turns text into digests (MD5, SHA family, CRC…) and
 * password hashes (bcrypt, Argon2, SHA-512 crypt), and checks text against an
 * existing hash. Nothing is stored or logged: the text only lives in the request.
 *
 * Encoding (Base64, hex, URL) happens in the browser; it needs no server.
 */
class HashForgeController extends Controller
{
    /** Digest algorithms offered, in display order: id => [label, group]. */
    public const DIGESTS = [
        'md5' => ['MD5', 'classic'],
        'sha1' => ['SHA-1', 'classic'],
        'sha224' => ['SHA-224', 'sha2'],
        'sha256' => ['SHA-256', 'sha2'],
        'sha384' => ['SHA-384', 'sha2'],
        'sha512' => ['SHA-512', 'sha2'],
        'sha512/256' => ['SHA-512/256', 'sha2'],
        'sha3-256' => ['SHA3-256', 'sha3'],
        'sha3-384' => ['SHA3-384', 'sha3'],
        'sha3-512' => ['SHA3-512', 'sha3'],
        'ripemd160' => ['RIPEMD-160', 'other'],
        'whirlpool' => ['Whirlpool', 'other'],
        'md4' => ['MD4 (NTLM base)', 'classic'],
        'crc32b' => ['CRC32', 'checksum'],
        'adler32' => ['Adler-32', 'checksum'],
        'xxh64' => ['xxHash64', 'checksum'],
        'xxh3' => ['XXH3', 'checksum'],
        'xxh128' => ['XXH128', 'checksum'],
        'fnv1a64' => ['FNV-1a 64', 'checksum'],
        'murmur3f' => ['MurmurHash3 128', 'checksum'],
    ];

    /** Password hash formats: id => label. */
    public const PASSWORDS = [
        'bcrypt' => 'bcrypt',
        'argon2i' => 'Argon2i',
        'argon2id' => 'Argon2id',
        'sha512crypt' => 'SHA-512 crypt ($6$)',
        'sha256crypt' => 'SHA-256 crypt ($5$)',
        'md5crypt' => 'MD5 crypt ($1$)',
    ];

    private const MAX_TEXT = 1_048_576; // 1 MB for digests

    private const MAX_PASSWORD = 4096;

    /** Upper bound on how many wordlist candidates a single crack request may try. */
    private const MAX_WORDLIST = 200_000;

    /** Hard ceiling on brute-force combinations, and the wall-clock budget for a crack run. */
    private const BRUTE_CAP = 500_000;

    private const CRACK_BUDGET = 15.0; // seconds

    /** Character pools offered for brute-force, in menu order. */
    private const CHARSETS = [
        'digits' => '0123456789',
        'lower' => 'abcdefghijklmnopqrstuvwxyz',
        'upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'symbols' => '!@#$%^&*()-_=+',
    ];

    /** Built-in wordlist sets the crack tab can mix and match. */
    private const WORDLIST_SETS = ['common', 'names', 'keyboard', 'numbers'];

    /** Hash byte-lengths (after hex-decode) mapped to the digests that produce them, for identification. */
    private const LENGTH_HINTS = [
        16 => ['MD5', 'MD4', 'MD5 crypt', 'NTLM'],
        20 => ['SHA-1', 'RIPEMD-160'],
        28 => ['SHA-224', 'SHA-512/224'],
        32 => ['SHA-256', 'SHA3-256', 'SHA-512/256', 'BLAKE2s'],
        48 => ['SHA-384', 'SHA3-384'],
        64 => ['SHA-512', 'SHA3-512', 'Whirlpool', 'BLAKE2b'],
    ];

    public function index(): Response
    {
        $digests = collect(self::DIGESTS)
            ->filter(fn ($_, string $algo): bool => in_array($algo, hash_algos(), true))
            ->map(fn (array $meta, string $algo): array => ['id' => $algo, 'label' => $meta[0], 'group' => $meta[1]])
            ->values();

        $passwords = collect(self::PASSWORDS)
            ->filter(fn ($_, string $id): bool => $this->passwordSupported($id))
            ->map(fn (string $label, string $id): array => ['id' => $id, 'label' => $label])
            ->values();

        $sets = array_map(fn (string $id): array => [
            'id' => $id,
            'count' => $this->wordlistSetCount($id),
        ], self::WORDLIST_SETS);

        return Inertia::render('Tools/HashForge', [
            'digests' => $digests,
            'passwords' => $passwords,
            'limits' => ['text' => self::MAX_TEXT, 'password' => self::MAX_PASSWORD, 'bruteCap' => self::BRUTE_CAP],
            'wordlistSets' => $sets,
            'charsets' => array_keys(self::CHARSETS),
        ]);
    }

    /** Hash the text with every requested algorithm. */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:'.self::MAX_TEXT],
            'digests' => ['array'],
            'digests.*' => ['string'],
            'passwords' => ['array'],
            'passwords.*' => ['string'],
            'hmac_key' => ['nullable', 'string', 'max:4096'],
            'uppercase' => ['boolean'],
            'bcrypt_cost' => ['integer', 'min:4', 'max:14'],
        ]);

        $text = (string) ($validated['text'] ?? '');
        $key = (string) ($validated['hmac_key'] ?? '');
        $upper = (bool) ($validated['uppercase'] ?? false);
        $results = [];

        foreach (array_unique($validated['digests'] ?? []) as $algo) {
            if (! isset(self::DIGESTS[$algo]) || ! in_array($algo, hash_algos(), true)) {
                continue;
            }
            $hmac = $key !== '' && in_array($algo, hash_hmac_algos(), true);
            $value = $hmac ? hash_hmac($algo, $text, $key) : hash($algo, $text);
            $results[] = [
                'id' => $algo,
                'label' => ($hmac ? 'HMAC-' : '').self::DIGESTS[$algo][0],
                'kind' => 'digest',
                'value' => $upper ? strtoupper($value) : $value,
                'bits' => strlen($value) * 4,
            ];
        }

        $passwordAlgos = array_unique($validated['passwords'] ?? []);
        if ($passwordAlgos && strlen($text) > self::MAX_PASSWORD) {
            return response()->json(['message' => __('Password hashes take at most :n characters.', ['n' => self::MAX_PASSWORD])], 422);
        }

        foreach ($passwordAlgos as $id) {
            if (! isset(self::PASSWORDS[$id]) || ! $this->passwordSupported($id)) {
                continue;
            }
            $results[] = [
                'id' => $id,
                'label' => self::PASSWORDS[$id],
                'kind' => 'password',
                'value' => $this->passwordHash($id, $text, (int) ($validated['bcrypt_cost'] ?? 10)),
                'bits' => null,
            ];
        }

        return response()->json([
            'results' => $results,
            'bytes' => strlen($text),
            // bcrypt only reads the first 72 bytes; say so instead of hashing silently.
            'bcrypt_truncated' => in_array('bcrypt', $passwordAlgos, true) && strlen($text) > 72,
        ]);
    }

    /** Check text against a hash: password_verify for salted formats, otherwise compare every digest of that length. */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:'.self::MAX_TEXT],
            'hash' => ['required', 'string', 'max:1024'],
            'hmac_key' => ['nullable', 'string', 'max:4096'],
        ]);

        $text = (string) ($validated['text'] ?? '');
        $hash = trim($validated['hash']);
        $key = (string) ($validated['hmac_key'] ?? '');

        // Salted formats: bcrypt, Argon2, crypt(3).
        $info = password_get_info($hash);
        if ($info['algo'] !== null && $info['algo'] !== 0) {
            return response()->json([
                'match' => strlen($text) <= self::MAX_PASSWORD && password_verify($text, $hash),
                'algorithm' => $info['algoName'],
                'candidates' => [$info['algoName']],
            ]);
        }
        if (preg_match('/^\$(1|5|6)\$/', $hash, $m)) {
            $name = ['1' => 'MD5 crypt', '5' => 'SHA-256 crypt', '6' => 'SHA-512 crypt'][$m[1]];

            return response()->json([
                'match' => strlen($text) <= self::MAX_PASSWORD && hash_equals($hash, crypt($text, $hash)),
                'algorithm' => $name,
                'candidates' => [$name],
            ]);
        }

        // Unsalted digests: hex of a known length.
        $hex = strtolower($hash);
        if (! ctype_xdigit($hex)) {
            return response()->json(['match' => false, 'algorithm' => null, 'candidates' => [], 'unknown' => true]);
        }

        $candidates = [];
        foreach (self::DIGESTS as $algo => [$label]) {
            if (! in_array($algo, hash_algos(), true) || strlen(hash($algo, '')) !== strlen($hex)) {
                continue;
            }
            $candidates[] = $label;
            $digest = $key !== '' && in_array($algo, hash_hmac_algos(), true)
                ? hash_hmac($algo, $text, $key)
                : hash($algo, $text);
            if (hash_equals($digest, $hex)) {
                return response()->json([
                    'match' => true,
                    'algorithm' => ($key !== '' ? 'HMAC-' : '').$label,
                    'candidates' => $candidates,
                ]);
            }
        }

        return response()->json(['match' => false, 'algorithm' => null, 'candidates' => $candidates, 'unknown' => $candidates === []]);
    }

    /**
     * Identify what a hash string might be, from its shape alone. Offline, no guessing of the plaintext.
     */
    public function identify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hash' => ['required', 'string', 'max:1024'],
        ]);
        $hash = trim($validated['hash']);

        // Salted / self-describing formats carry their scheme in a $id$ prefix.
        $info = password_get_info($hash);
        if ($info['algo'] !== null && $info['algo'] !== 0) {
            return response()->json([
                'format' => $info['algoName'],
                'salted' => true,
                'candidates' => [$info['algoName']],
                'note' => 'crackable',
            ]);
        }
        if (preg_match('/^\$(1|5|6)\$/', $hash, $m)) {
            $name = ['1' => 'MD5 crypt ($1$)', '5' => 'SHA-256 crypt ($5$)', '6' => 'SHA-512 crypt ($6$)'][$m[1]];

            return response()->json(['format' => $name, 'salted' => true, 'candidates' => [$name], 'note' => 'crackable']);
        }

        $hex = strtolower(preg_replace('/^0x/i', '', $hash));
        if (! ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
            return response()->json(['format' => null, 'salted' => false, 'candidates' => [], 'note' => 'unknown']);
        }

        $bytes = strlen($hex) / 2;
        $candidates = self::LENGTH_HINTS[$bytes] ?? [];

        return response()->json([
            'format' => null,
            'salted' => false,
            'bits' => $bytes * 8,
            'candidates' => $candidates,
            'note' => $candidates ? 'crackable' : 'unknown',
        ]);
    }

    /**
     * Offline dictionary attack: try each wordlist entry against the hash and report the first match.
     * Supplements the built-in weak-password list with any words the caller pastes in. Network is never touched.
     */
    public function crack(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hash' => ['required', 'string', 'max:1024'],
            'wordlist' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'hmac_key' => ['nullable', 'string', 'max:4096'],
            'sets' => ['array'],
            'sets.*' => ['string'],
            'algos' => ['array'],
            'algos.*' => ['string'],
            'brute' => ['boolean'],
            'brute_charset' => ['array'],
            'brute_charset.*' => ['string', 'in:'.implode(',', array_keys(self::CHARSETS))],
            'brute_custom' => ['nullable', 'string', 'max:256'],
            'brute_min' => ['integer', 'min:1', 'max:8'],
            'brute_max' => ['integer', 'min:1', 'max:8'],
        ]);

        $hash = trim($validated['hash']);
        $key = (string) ($validated['hmac_key'] ?? '');

        // Assemble the candidate sources in the order they will be tried: pasted
        // words first, then each selected built-in set, then any brute-force sweep.
        $sources = [];
        $custom = $this->linesOf($validated['wordlist'] ?? '');
        if ($custom !== []) {
            $sources[] = $custom;
        }
        foreach (array_intersect($validated['sets'] ?? [], self::WORDLIST_SETS) as $id) {
            $sources[] = $this->wordlistSet($id);
        }

        if ($validated['brute'] ?? false) {
            $pool = $this->brutecharPool($validated['brute_charset'] ?? [], (string) ($validated['brute_custom'] ?? ''));
            $min = (int) ($validated['brute_min'] ?? 1);
            $max = max($min, (int) ($validated['brute_max'] ?? 4));
            if ($pool === '') {
                return response()->json(['message' => __('Pick at least one character set for brute-force.')], 422);
            }
            $combos = $this->bruteCombos(strlen($pool), $min, $max);
            if ($combos > self::BRUTE_CAP) {
                return response()->json(['message' => __('That brute-force range is too large (:n combinations, limit :cap). Narrow the character set or length.', ['n' => $combos, 'cap' => self::BRUTE_CAP])], 422);
            }
            $sources[] = $this->bruteCandidates($pool, $min, $max);
        }

        if ($sources === []) {
            return response()->json(['message' => __('Choose a wordlist set, paste words, or enable brute-force.')], 422);
        }

        $matcher = $this->matcherFor($hash, $key, $validated['algos'] ?? []);
        if ($matcher === null) {
            return response()->json(['found' => false, 'tried' => 0, 'unknown' => true]);
        }

        $tried = 0;
        $deadline = microtime(true) + self::CRACK_BUDGET;
        foreach ($sources as $source) {
            foreach ($source as $candidate) {
                $candidate = (string) $candidate;
                $tried++;
                if (($matcher)($candidate)) {
                    return response()->json(['found' => true, 'plaintext' => $candidate, 'tried' => $tried]);
                }
                // Salted hashes are slow, so check the clock every so often and bail with a partial result.
                if (($tried & 0x1FF) === 0 && microtime(true) > $deadline) {
                    return response()->json(['found' => false, 'tried' => $tried, 'stopped' => 'time']);
                }
            }
        }

        return response()->json(['found' => false, 'tried' => $tried]);
    }

    /**
     * Rate the strength of a password (and optionally flag that it echoes a username), fully offline.
     */
    public function audit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['present', 'nullable', 'string', 'max:'.self::MAX_PASSWORD],
            'username' => ['nullable', 'string', 'max:256'],
        ]);

        $password = (string) ($validated['password'] ?? '');
        $username = trim((string) ($validated['username'] ?? ''));
        $lower = strtolower($password);
        $reasons = [];

        if ($password === '') {
            return response()->json(['score' => 0, 'label' => 'empty', 'reasons' => ['empty'], 'guessable' => false, 'entropyBits' => 0]);
        }

        $builtin = $this->weakPasswordSet();
        $guessable = isset($builtin[$lower]);
        if ($guessable) {
            $reasons[] = 'in_common_list';
        }
        if ($username !== '' && $lower === strtolower($username)) {
            $reasons[] = 'equals_username';
            $guessable = true;
        } elseif ($username !== '' && str_contains($lower, strtolower($username)) && strlen($username) >= 3) {
            $reasons[] = 'contains_username';
        }

        $classes = (preg_match('/[a-z]/', $password) ? 1 : 0)
            + (preg_match('/[A-Z]/', $password) ? 1 : 0)
            + (preg_match('/\d/', $password) ? 1 : 0)
            + (preg_match('/[^A-Za-z0-9]/', $password) ? 1 : 0);
        $len = strlen($password);

        if ($len < 8) {
            $reasons[] = 'too_short';
        }
        if ($classes < 3) {
            $reasons[] = 'few_character_types';
        }
        if (preg_match('/^[a-z]+$/', $password) || preg_match('/^\d+$/', $password)) {
            $reasons[] = 'single_class';
        }
        if (preg_match('/(.)\1{2,}/', $password)) {
            $reasons[] = 'repeated_characters';
        }
        if (preg_match('/(012|123|234|345|456|567|678|789|abc|qwe|asd)/i', $password)) {
            $reasons[] = 'sequential';
        }

        // Rough Shannon-style entropy from the character pool actually used.
        $pool = (preg_match('/[a-z]/', $password) ? 26 : 0)
            + (preg_match('/[A-Z]/', $password) ? 26 : 0)
            + (preg_match('/\d/', $password) ? 10 : 0)
            + (preg_match('/[^A-Za-z0-9]/', $password) ? 33 : 0);
        $entropy = $pool > 0 ? (int) round($len * log($pool, 2)) : 0;

        if ($guessable) {
            $score = 0;
        } elseif ($entropy < 28 || $len < 8) {
            $score = 1;
        } elseif ($entropy < 50) {
            $score = 2;
        } elseif ($entropy < 70) {
            $score = 3;
        } else {
            $score = 4;
        }
        $label = ['very_weak', 'weak', 'fair', 'strong', 'very_strong'][$score];

        return response()->json([
            'score' => $score,
            'label' => $label,
            'reasons' => array_values(array_unique($reasons)),
            'guessable' => $guessable,
            'entropyBits' => $entropy,
            'length' => $len,
            'classes' => $classes,
        ]);
    }

    /** Split pasted text into a de-duplicated, order-preserving list of non-empty lines. */
    private function linesOf(string $pasted): array
    {
        $words = [];
        foreach (preg_split('/\R/', $pasted) ?: [] as $line) {
            $line = rtrim($line, "\r");
            if ($line !== '') {
                $words[$line] = true;
            }
        }

        return array_keys($words);
    }

    /** Candidate list for a built-in wordlist set. */
    private function wordlistSet(string $id): iterable
    {
        return match ($id) {
            'common' => array_keys($this->weakPasswordSet()),
            'names' => $this->fileWords('data/common-names.txt'),
            'keyboard' => ['qwerty', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm', 'qazwsx', 'qazwsxedc', '1qaz2wsx',
                '1q2w3e4r', '1q2w3e4r5t', 'zaq12wsx', 'qwer1234', 'asdf1234', '1234qwer', 'poiuytrewq',
                'mnbvcxz', 'lkjhgfdsa', 'qweasd', 'qwaszx', 'qazwsxedc', 'qwertz', 'azerty', 'wsxedc',
                'edcrfv', 'rfvtgb', 'qwe123', 'asd123', 'zxc123', '147258', '159357', '753951', '258456'],
            'numbers' => (function () {
                for ($i = 0; $i <= 9999; $i++) {
                    yield sprintf('%04d', $i);
                }
            })(),
            default => [],
        };
    }

    /** How many candidates a built-in set holds, for the UI. */
    private function wordlistSetCount(string $id): int
    {
        return match ($id) {
            'common' => count($this->weakPasswordSet()),
            'names' => count($this->fileWords('data/common-names.txt')),
            'keyboard' => count($this->wordlistSet('keyboard')),
            'numbers' => 10000,
            default => 0,
        };
    }

    /** Read a resource word file as a de-duplicated, lowercased list. */
    private function fileWords(string $relative): array
    {
        $path = resource_path($relative);
        if (! is_readable($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[strtolower($line)] = true;
            }
        }

        return array_keys($out);
    }

    /** Join the selected character pools plus any custom characters into one de-duplicated string. */
    private function brutecharPool(array $selected, string $custom = ''): string
    {
        $pool = '';
        foreach (self::CHARSETS as $id => $chars) {
            if (in_array($id, $selected, true)) {
                $pool .= $chars;
            }
        }
        $pool .= $custom;

        return count_chars($pool, 3); // unique characters, stable order
    }

    /** Total brute-force combinations for a pool size across the length range. */
    private function bruteCombos(int $pool, int $min, int $max): int
    {
        $total = 0;
        for ($len = $min; $len <= $max; $len++) {
            $total += $pool ** $len;
            if ($total > self::BRUTE_CAP) {
                return $total; // early out; the caller only needs to know it exceeds the cap
            }
        }

        return $total;
    }

    /** Yield every string over $pool of length $min..$max, shortest first. */
    private function bruteCandidates(string $pool, int $min, int $max): iterable
    {
        $chars = str_split($pool);
        $n = count($chars);
        for ($len = $min; $len <= $max; $len++) {
            $odometer = array_fill(0, $len, 0);
            while (true) {
                $word = '';
                foreach ($odometer as $pos) {
                    $word .= $chars[$pos];
                }
                yield $word;

                // Increment the odometer from the right.
                for ($i = $len - 1; $i >= 0; $i--) {
                    if (++$odometer[$i] < $n) {
                        break;
                    }
                    $odometer[$i] = 0;
                    if ($i === 0) {
                        break 2;
                    }
                }
            }
        }
    }

    /**
     * Return a closure that reports whether a candidate plaintext produces the given hash, or null if the
     * format is unknown. $onlyAlgos, when given, restricts an unsalted match to those digest ids.
     */
    private function matcherFor(string $hash, string $key, array $onlyAlgos = []): ?callable
    {
        $info = password_get_info($hash);
        if (($info['algo'] !== null && $info['algo'] !== 0) || preg_match('/^\$(1|5|6)\$/', $hash)) {
            // Salted formats carry their own salt; crypt() re-derives it from the stored prefix.
            return fn (string $candidate): bool => strlen($candidate) <= self::MAX_PASSWORD
                && ($info['algo'] ? password_verify($candidate, $hash) : hash_equals($hash, crypt($candidate, $hash)));
        }

        $hex = strtolower(preg_replace('/^0x/i', '', trim($hash)));
        if (! ctype_xdigit($hex)) {
            return null;
        }
        $algos = array_values(array_filter(
            array_keys(self::DIGESTS),
            fn (string $algo): bool => in_array($algo, hash_algos(), true)
                && strlen(hash($algo, '')) === strlen($hex)
                && ($onlyAlgos === [] || in_array($algo, $onlyAlgos, true)),
        ));
        if ($algos === []) {
            return null;
        }

        return function (string $candidate) use ($algos, $hex, $key): bool {
            foreach ($algos as $algo) {
                $digest = $key !== '' && in_array($algo, hash_hmac_algos(), true)
                    ? hash_hmac($algo, $candidate, $key)
                    : hash($algo, $candidate);
                if (hash_equals($digest, $hex)) {
                    return true;
                }
            }

            return false;
        };
    }

    /** Lazily load the built-in weak-password list as a lookup set (lowercased). */
    private function weakPasswordSet(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }
        $set = [];
        $path = resource_path('data/weak-passwords.txt');
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $set[strtolower($line)] = true;
                }
            }
        }

        return $set;
    }

    private function passwordSupported(string $id): bool
    {
        return match ($id) {
            'argon2i' => defined('PASSWORD_ARGON2I'),
            'argon2id' => defined('PASSWORD_ARGON2ID'),
            'sha512crypt' => defined('CRYPT_SHA512') && CRYPT_SHA512 === 1,
            'sha256crypt' => defined('CRYPT_SHA256') && CRYPT_SHA256 === 1,
            'md5crypt' => defined('CRYPT_MD5') && CRYPT_MD5 === 1,
            default => true,
        };
    }

    private function passwordHash(string $id, string $text, int $cost): string
    {
        $salt = fn (int $length): string => substr(strtr(base64_encode(random_bytes($length)), '+', '.'), 0, $length);

        return match ($id) {
            'bcrypt' => password_hash($text, PASSWORD_BCRYPT, ['cost' => $cost]),
            'argon2i' => password_hash($text, PASSWORD_ARGON2I),
            'argon2id' => password_hash($text, PASSWORD_ARGON2ID),
            'sha512crypt' => crypt($text, '$6$rounds=5000$'.$salt(16).'$'),
            'sha256crypt' => crypt($text, '$5$rounds=5000$'.$salt(16).'$'),
            'md5crypt' => crypt($text, '$1$'.$salt(8).'$'),
        };
    }
}
