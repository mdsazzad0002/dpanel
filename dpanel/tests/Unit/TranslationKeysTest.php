<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Backlog U2: every locale has the same keys, and every key a page uses exists. */
class TranslationKeysTest extends TestCase
{
    private const LOCALES = __DIR__.'/../../resources/js/i18n/locales';

    /** @return list<string> */
    private static function keys(array $messages, string $prefix = ''): array
    {
        $keys = [];
        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = [...$keys, ...(is_array($value) ? self::keys($value, $path) : [$path])];
        }

        return $keys;
    }

    private static function load(string $code): array
    {
        return json_decode((string) file_get_contents(self::LOCALES."/{$code}.json"), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_english_and_bangla_have_the_same_keys(): void
    {
        $en = self::keys(self::load('en'));
        $bn = self::keys(self::load('bn'));
        sort($en);
        sort($bn);

        $this->assertSame([], array_values(array_diff($en, $bn)), 'Missing in bn.json');
        $this->assertSame([], array_values(array_diff($bn, $en)), 'Missing in en.json');
    }

    public function test_every_key_a_page_uses_is_in_english(): void
    {
        $known = array_flip(self::keys(self::load('en')));
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../resources/js'));
        $missing = [];
        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'js'], true)) {
                continue;
            }
            preg_match_all("/\\bt\\('([a-zA-Z0-9_.]+)'/", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $key) {
                if (str_contains($key, '.') && ! isset($known[$key])) {
                    $missing[] = $file->getFilename().': '.$key;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    public function test_placeholders_match_between_locales(): void
    {
        $en = self::load('en');
        $bn = self::load('bn');
        foreach (self::keys($en) as $key) {
            $a = data_get($en, $key);
            $b = data_get($bn, $key);
            preg_match_all('/\{(\w+)\}/', $a, $ma);
            preg_match_all('/\{(\w+)\}/', (string) $b, $mb);
            sort($ma[1]);
            sort($mb[1]);
            $this->assertSame($ma[1], $mb[1], "Placeholders differ for {$key}");
        }
    }
}
