<?php

namespace Tests\Unit;

use App\Jobs\WordpressStagingFinishJob;
use App\Services\Wordpress\WordpressInfo;
use App\Services\Wordpress\WordpressSecurityReport;
use PHPUnit\Framework\TestCase;

class WordpressSecurityReportTest extends TestCase
{
    private static function r(bool $ok, string $output = '', string $error = ''): array
    {
        return ['ok' => $ok, 'output' => $output, 'error' => $error];
    }

    private static function byKey(array $checks): array
    {
        return array_column($checks, null, 'key');
    }

    public function test_a_clean_site_passes_and_offers_no_fixes(): void
    {
        $checks = self::byKey(WordpressSecurityReport::fromResults([
            'core_checksums' => self::r(true, 'Success: WordPress installation verifies against checksums.'),
            'plugin_checksums' => self::r(true, '[]'),
            'admins' => self::r(true, "sam\nlee"),
            'registration' => self::r(true, '0'),
            'debug' => self::r(true, 'false'),
            'file_edit' => self::r(true, '1'),
            'core_updates' => self::r(true, ''),
            'plugins' => self::r(true, '[{"name":"akismet","status":"active","version":"5.3","update":"none"}]'),
        ]));

        $this->assertSame(['pass'], array_values(array_unique(array_column($checks, 'status'))));
        $this->assertSame([null], array_values(array_unique(array_column($checks, 'fix'))));
    }

    public function test_problems_are_named_with_their_fix(): void
    {
        $checks = self::byKey(WordpressSecurityReport::fromResults([
            'core_checksums' => self::r(false, '', "Warning: File doesn't verify against checksum: wp-login.php\nWarning: File should not exist: wp-includes/x.php\nError: WordPress installation doesn't verify against checksums."),
            'plugin_checksums' => self::r(false, '[{"plugin_name":"hello","file":"hello.php","message":"Checksum does not match"},{"plugin_name":"premium","file":"","message":"Could not retrieve the checksums for version 1 of plugin premium, skipping."}]'),
            'admins' => self::r(true, "Admin\nsam"),
            'registration' => self::r(true, '1'),
            'debug' => self::r(true, 'true'),
            'file_edit' => self::r(false, '', "Error: The constant 'DISALLOW_FILE_EDIT' is not defined"),
            'core_updates' => self::r(true, 'PHP Notice: x'."\n".'[{"version":"6.8.1","update_type":"minor"},{"version":"6.9","update_type":"major"}]'),
            'plugins' => self::r(true, '[{"name":"hello","update":"available","update_version":"2.0"}]'),
        ]));

        $this->assertSame('fail', $checks['core_files']['status']);
        $this->assertSame('reinstall_core', $checks['core_files']['fix']);
        $this->assertSame('wp-login.php, wp-includes/x.php', $checks['core_files']['detail']);
        $this->assertSame('hello', $checks['plugin_files']['detail'], 'a plugin with no published checksums is not a change');
        $this->assertSame('admin', $checks['admin_names']['detail']);
        $this->assertSame('close_registration', $checks['registration']['fix']);
        $this->assertSame('disable_debug', $checks['debug']['fix']);
        $this->assertSame('disallow_file_edit', $checks['file_edit']['fix']);
        $this->assertSame('6.9', $checks['core_update']['detail']);
        $this->assertSame('hello', $checks['plugin_updates']['detail']);
        foreach ($checks as $check) {
            $this->assertTrue($check['fix'] === null || in_array($check['fix'], WordpressSecurityReport::FIXES, true));
        }
    }

    public function test_info_reads_plugins_themes_and_the_newest_core_update(): void
    {
        $info = WordpressInfo::fromResults([
            'core_version' => self::r(true, "6.8\n"),
            'core_updates' => self::r(true, '[{"version":"6.8.1"},{"version":"6.10"}]'),
            'plugins' => self::r(true, '[{"name":"woo","title":"Woo &amp; Co","status":"active","version":"9.0","update":"available","update_version":"9.1"},{"name":"akismet","title":"Akismet","status":"inactive","version":"5.3","update":"none"}]'),
            'themes' => self::r(true, 'not json'),
        ]);

        $this->assertSame('6.8', $info['version']);
        $this->assertSame('6.10', $info['core_update']);
        $this->assertSame('Woo & Co', $info['plugins'][0]['title']);
        $this->assertSame('9.1', $info['plugins'][0]['update']);
        $this->assertNull($info['plugins'][1]['update']);
        $this->assertSame([], $info['themes']);
    }

    public function test_the_target_address_keeps_scheme_port_and_path(): void
    {
        $this->assertSame('https://staging.shop.test', WordpressStagingFinishJob::targetHome('https://shop.test/', 'Staging.Shop.Test'));
        $this->assertSame('http://staging.test:8080/blog', WordpressStagingFinishJob::targetHome('http://shop.test:8080/blog/', 'staging.test'));
    }
}
