<?php

namespace Tests\Unit;

use App\Http\Controllers\Website\WebsiteController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An uploaded, renamed or copied file has to keep the name the user gave it:
 * anything else leaves them looking for a file that is not there.
 */
class FilemanagerFilenameTest extends TestCase
{
    private function sanitize(string $name): string
    {
        $controller = new class extends WebsiteController
        {
            public function __construct() {}

            public function call(string $name): string
            {
                return $this->sanitizeFilename($name);
            }
        };

        return $controller->call($name);
    }

    /** @return array<string, array{string}> */
    public static function keptNames(): array
    {
        return [
            'spaces' => ['My Report 2026.pdf'],
            'brackets' => ['invoice (1).png'],
            'ampersand' => ['terms & conditions.docx'],
            'accents' => ['résumé final.pdf'],
            'bangla' => ['আমার-ফাইল.txt'],
            'arabic' => ['ملف.zip'],
            'emoji' => ['screenshot 🎉.png'],
            'hash and comma' => ['notes #1, draft.md'],
            'quotes' => ["o'brien's file.csv"],
            'plus and equals' => ['a+b=c.txt'],
            'dot folders' => ['.env.production'],
            'uppercase extension' => ['PHOTO.JPG'],
        ];
    }

    #[DataProvider('keptNames')]
    public function test_the_name_the_user_chose_is_kept(string $name): void
    {
        $this->assertSame($name, $this->sanitize($name));
    }

    public function test_only_the_unusable_parts_are_taken_out(): void
    {
        // Path separators would move the file out of the folder it was dropped in.
        $this->assertSame('etc-passwd', $this->sanitize('etc/passwd'));
        $this->assertSame('..-..-etc-passwd', $this->sanitize('../../etc/passwd'));
        $this->assertSame('a-b.txt', $this->sanitize('a\\b.txt'));
        // Control characters and NUL.
        $this->assertSame('report.pdf', $this->sanitize("rep\x00ort\x07.pdf"));
        // Surrounding whitespace is not something a user can see or match.
        $this->assertSame('photo.png', $this->sanitize("  photo.png \n"));
    }

    public function test_names_that_cannot_be_a_file_are_rejected(): void
    {
        $this->assertSame('', $this->sanitize(''));
        $this->assertSame('', $this->sanitize('   '));
        $this->assertSame('', $this->sanitize('.'));
        $this->assertSame('', $this->sanitize('..'));
        $this->assertSame('', $this->sanitize('...'));
        // 255 bytes is the cap on every filesystem dpanel runs on; a longer
        // name is refused instead of being silently cut short.
        $this->assertSame(str_repeat('a', 255), $this->sanitize(str_repeat('a', 255)));
        $this->assertSame('', $this->sanitize(str_repeat('a', 256)));
        $this->assertSame('', $this->sanitize(str_repeat('আ', 86)));
    }
}
