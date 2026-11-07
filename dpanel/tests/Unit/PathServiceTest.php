<?php

namespace Tests\Unit;

use App\Services\PathService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PathServiceTest extends TestCase
{
    private const WINDOWS_BASE = 'C:\\Users\\First Last\\dPanel\\sites';

    private function useBase(string $base): PathService
    {
        config()->set('app.server_base_dir', $base);

        return new PathService;
    }

    public function test_the_default_base_stays_home(): void
    {
        $paths = $this->useBase('');

        $this->assertSame('/home', PathService::websiteBaseDirectory());
        $this->assertSame('/home/example_com', PathService::siteHome('example_com'));
        $this->assertSame([
            'site_owner' => 'example_com',
            'site_dir' => 'public_html',
            'root_path' => '/home/example_com/public_html',
            'project_root' => '/home/example_com',
        ], $paths->deriveWebsiteLayout('Example.com'));
    }

    public function test_posix_paths_keep_their_case(): void
    {
        $paths = $this->useBase('');

        $this->assertTrue($paths->pathStartsWith('/home/site', '/home/'));
        $this->assertFalse($paths->pathStartsWith('/HOME/site', '/home/'));
        $this->assertFalse(PathService::isInsideSiteBase('/Home/site/public_html'));
        $this->assertNull(PathService::relativeTo('/home/Site/x', '/home/site'));
    }

    public function test_the_default_base_only_accepts_paths_below_it(): void
    {
        $this->useBase('');

        $this->assertTrue(PathService::isInsideSiteBase('/home/site/public_html'));
        $this->assertFalse(PathService::isInsideSiteBase('/home'));
        $this->assertFalse(PathService::isInsideSiteBase('/homeless/site'));
        $this->assertFalse(PathService::isInsideSiteBase('/var/www/site'));
        $this->assertFalse(PathService::isInsideSiteBase('C:/home/site'));
    }

    public function test_a_windows_base_uses_forward_slashes_and_keeps_spaces(): void
    {
        $this->useBase(self::WINDOWS_BASE.'\\');

        $this->assertSame('C:/Users/First Last/dPanel/sites', PathService::websiteBaseDirectory());
        $this->assertSame('C:/Users/First Last/dPanel/sites/example_com', PathService::siteHome('example_com'));
    }

    public function test_a_drive_root_base_still_builds_one_slash_paths(): void
    {
        $this->useBase('D:\\');

        $this->assertSame('D:/example_com', PathService::siteHome('example_com'));
        $this->assertTrue(PathService::isInsideSiteBase('d:\\example_com\\public_html'));
    }

    public function test_layout_on_a_windows_base(): void
    {
        $layout = $this->useBase(self::WINDOWS_BASE)->deriveWebsiteLayout('my-site.test');

        $this->assertSame('my_site_test', $layout['site_owner']);
        $this->assertSame('C:/Users/First Last/dPanel/sites/my_site_test', $layout['project_root']);
        $this->assertSame('C:/Users/First Last/dPanel/sites/my_site_test/public_html', $layout['root_path']);
    }

    /** @return array<string, array{0: string}> */
    public static function sameFolderInAnotherSpelling(): array
    {
        return [
            'backslashes' => ['C:\\Users\\First Last\\dPanel\\sites\\owner\\public_html'],
            'lower drive' => ['c:/Users/First Last/dPanel/sites/owner/public_html'],
            'other case' => ['C:/USERS/first last/DPANEL/Sites/owner/public_html'],
            'mixed slashes' => ['c:\\users/First Last\\dpanel/sites\\owner/public_html'],
        ];
    }

    #[DataProvider('sameFolderInAnotherSpelling')]
    public function test_windows_paths_ignore_case_and_slash_direction(string $path): void
    {
        $paths = $this->useBase(self::WINDOWS_BASE);

        $this->assertTrue(PathService::isInsideSiteBase($path));
        $this->assertSame('owner', $paths->extractSiteOwnerFromRootPath($path));
        $this->assertSame('owner/public_html', PathService::relativeTo($path, self::WINDOWS_BASE));
        $this->assertSame(str_replace('\\', '/', $path), $paths->normalizeRootPath($path, 'owner.test'));
    }

    public function test_a_similar_windows_folder_is_outside_the_base(): void
    {
        $paths = $this->useBase(self::WINDOWS_BASE);

        foreach (['C:/Users/First Last/dPanel/sites-old/owner', 'D:/Users/First Last/dPanel/sites/owner', '/home/owner/public_html'] as $outside) {
            $this->assertFalse(PathService::isInsideSiteBase($outside), $outside);
            $this->assertNull(PathService::relativeTo($outside, self::WINDOWS_BASE), $outside);
            $this->assertSame('site_default', $paths->extractSiteOwnerFromRootPath($outside), $outside);
            $this->assertSame('C:/Users/First Last/dPanel/sites/owner_test/public_html', $paths->normalizeRootPath($outside, 'owner.test'), $outside);
        }
    }

    public function test_non_ascii_windows_folders_compare_without_case(): void
    {
        $this->useBase('C:\\Users\\Ärger\\sites');

        $this->assertTrue(PathService::isInsideSiteBase('c:/users/ärger/sites/owner'));
        $this->assertSame('owner/public_html', PathService::relativeTo('c:/users/ärger/sites/owner/public_html', 'C:\\Users\\Ärger\\sites'));
    }

    public function test_project_root_comes_from_the_parent_of_a_windows_root(): void
    {
        $paths = $this->useBase(self::WINDOWS_BASE);

        $this->assertSame(
            'C:/Users/First Last/dPanel/sites/owner',
            $paths->deriveProjectRootPath('C:\\Users\\First Last\\dPanel\\sites\\owner\\public_html', 'owner.test'),
        );
    }

    public function test_relative_to_handles_the_base_itself_and_trailing_slashes(): void
    {
        $this->assertSame('', PathService::relativeTo('/home/site/', '/home/site'));
        $this->assertSame('', PathService::relativeTo('C:\\Sites\\', 'c:/sites'));
        $this->assertSame('a/b', PathService::relativeTo('/home/site/a/b/', '/home/site/'));
        $this->assertNull(PathService::relativeTo('/home/site2', '/home/site'));
        $this->assertNull(PathService::relativeTo('/anything', ''));
    }

    public function test_absolute_paths_are_recognised_on_any_os(): void
    {
        foreach (['/home/site', 'C:/sites', 'c:\\sites', 'C:\\'] as $absolute) {
            $this->assertTrue(PathService::isAbsolutePath($absolute), $absolute);
        }
        foreach (['', 'public_html', './x', '../x', 'C:sites', 'folder/C:/x'] as $relative) {
            $this->assertFalse(PathService::isAbsolutePath($relative), $relative);
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function plainDrivePaths(): array
    {
        return [
            'plain' => ['C:/Sites/shop/index.php', true],
            'backslashes' => ['C:\\Sites\\shop\\index.php', true],
            'drive root' => ['D:/', true],
            'spaces and dots inside a name' => ['C:/My Sites/a.b.c/index.php', true],
            'a name that starts like a device' => ['C:/Sites/console/null.php', true],
            'a tilde without a digit' => ['C:/Sites/~backup/a~b.txt', true],
            'POSIX' => ['/home/shop', false],
            'UNC' => ['\\\\server\\share\\x', false],
            'extended' => ['\\\\?\\C:\\x', false],
            'device' => ['\\\\.\\C:\\x', false],
            'drive relative' => ['C:x', false],
            'bare drive' => ['C:', false],
            'data stream' => ['C:/Sites/a.php::$DATA', false],
            'data stream on a folder' => ['C:/Sites::$INDEX_ALLOCATION/a.php', false],
            'named stream' => ['C:/Sites/a.php:x', false],
            'trailing dot' => ['C:/Sites/a.php.', false],
            'trailing dot on a folder' => ['C:/Sites./a.php', false],
            'parent' => ['C:/Sites/../a.php', false],
            'trailing space on a folder' => ['C:/Sites /a.php', false],
            '8.3 name' => ['C:/PROGRA~1/a.php', false],
            'wildcard' => ['C:/Sites/*.php', false],
            'pipe' => ['C:/Sites/a|b', false],
            'device name' => ['C:/Sites/NUL', false],
            'device in lower case with extension' => ['C:/Sites/com1.txt', false],
        ];
    }

    #[DataProvider('plainDrivePaths')]
    public function test_plain_drive_paths(string $path, bool $expected): void
    {
        $this->assertSame($expected, PathService::isPlainDrivePath($path));
    }
}
