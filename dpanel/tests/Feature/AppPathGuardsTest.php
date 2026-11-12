<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteGuardSetting;
use App\Services\Website\Apps\AppInstallerRegistry;
use App\Services\Website\Apps\AppPathGuards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The gateway ignores .htaccess, so an installed app's private folders become guard rows. */
class AppPathGuardsTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        'prestashop' => [
            'denied' => ['/app', '/bin', '/cache', '/classes', '/config', '/controllers', '/docs', '/download', '/localization', '/mails', '/override', '/src', '/upload', '/var', '/vendor'],
            'no_php' => ['/img', '/js', '/modules', '/themes'],
        ],
        'mediawiki' => [
            'denied' => ['/cache', '/images/deleted', '/images/temp', '/includes', '/languages', '/maintenance', '/tests'],
            'no_php' => ['/images'],
        ],
        'nextcloud' => [
            'denied' => ['/3rdparty', '/build', '/config', '/console.php', '/lib', '/occ', '/templates', '/tests'],
            'no_php' => [],
        ],
        'opencart' => ['denied' => ['/system/storage'], 'no_php' => ['/image']],
        'drupal' => ['denied' => [], 'no_php' => ['/sites/default/files']],
        'whmcs' => ['denied' => ['/attachments', '/downloads', '/templates_c'], 'no_php' => []],
        'joomla' => ['denied' => [], 'no_php' => []],
        'codeigniter' => ['denied' => [], 'no_php' => []],
    ];

    private function website(): Website
    {
        $owner = User::factory()->create();

        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner->id,
        ]);
    }

    public function test_every_app_in_the_catalog_has_expected_rules(): void
    {
        $this->assertEqualsCanonicalizing(AppInstallerRegistry::keys(), array_keys(self::EXPECTED));
    }

    public function test_installing_each_app_sets_its_rules(): void
    {
        $registry = app(AppInstallerRegistry::class);
        foreach (self::EXPECTED as $app => $expected) {
            $website = $this->website();
            app(AppPathGuards::class)->apply($website, $registry->get($app));
            $setting = WebsiteGuardSetting::query()->find($website->id);
            $this->assertEqualsCanonicalizing($expected['denied'], $setting?->deniedPaths() ?? [], "{$app} denied paths");
            $this->assertEqualsCanonicalizing($expected['no_php'], $setting?->noPhpPaths() ?? [], "{$app} no-PHP paths");
            $website->delete();
        }
    }

    public function test_a_new_app_replaces_the_old_deny_list_but_keeps_the_owners_settings(): void
    {
        $website = $this->website();
        WebsiteGuardSetting::query()->create([
            'website_id' => $website->id, 'no_php_paths' => '/uploads', 'denied_paths' => '/old',
            'hotlink_protection' => true, 'cloudflare_only' => true,
        ]);
        $registry = app(AppInstallerRegistry::class);

        app(AppPathGuards::class)->apply($website, $registry->get('mediawiki'));

        $setting = WebsiteGuardSetting::query()->findOrFail($website->id);
        $this->assertNotContains('/old', $setting->deniedPaths());
        $this->assertContains('/maintenance', $setting->deniedPaths());
        $this->assertSame(['/uploads', '/images'], $setting->noPhpPaths());
        $this->assertTrue($setting->hotlink_protection && $setting->cloudflare_only);

        app(AppPathGuards::class)->apply($website, $registry->get('joomla'));
        $this->assertSame([], WebsiteGuardSetting::query()->findOrFail($website->id)->deniedPaths());
    }
}
