<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebsiteCreateOnWindowsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'C:/Users/First Last/dPanel/sites';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.server_base_dir', 'C:\\Users\\First Last\\dPanel\\sites');
        config()->set('serverpanel.filemanager_api_url', 'http://drust.test/api/v1/filemanager');
        Bus::fake();
    }

    private function createSite(array $input, bool $drustAccepts = true): TestResponse
    {
        Http::fake(['http://drust.test/*' => $drustAccepts
            ? Http::response(['success' => true, 'message' => 'ok', 'data' => []])
            : Http::response(['success' => false, 'message' => 'home is not allowed'], 400)]);

        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->actingAsPanelUser($admin)
            ->postJson(route('websites.store'), $input + ['domain_type' => 'main', 'php_version' => '8.3']);
    }

    /** @return list<array<string, mixed>> */
    private function drustCalls(string $operation): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/'.$operation))
            ->map(fn (array $pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    public function test_a_site_is_created_below_the_configured_base(): void
    {
        $response = $this->createSite(['domain' => 'my-site.test', 'include_www' => false]);

        $response->assertOk()->assertJsonPath('type', 'success');

        $website = Website::query()->where('domain', 'my-site.test')->sole();
        $this->assertSame('my_site_test', $website->site_owner);
        $this->assertSame(self::BASE.'/my_site_test', $website->project_root);
        $this->assertSame(self::BASE.'/my_site_test/public_html', $website->root_path);

        $user = $this->drustCalls('user')[0];
        $this->assertSame('create', $user['action']);
        $this->assertSame(self::BASE.'/my_site_test', $user['home']);

        $this->assertSame(
            [self::BASE.'/my_site_test', self::BASE.'/my_site_test/public_html'],
            $this->drustCalls('create')[0]['paths'],
        );
        $this->assertContains(
            self::BASE.'/my_site_test/public_html/index.php',
            array_column($this->drustCalls('write'), 'path'),
        );
    }

    public function test_the_www_alias_shares_the_folder_of_its_site(): void
    {
        $this->createSite(['domain' => 'shop.test', 'include_www' => true])->assertOk();

        $alias = Website::query()->where('domain', 'www.shop.test')->sole();
        $this->assertSame(self::BASE.'/shop_test/public_html', $alias->root_path);
        $this->assertCount(1, $this->drustCalls('user'));
    }

    public function test_a_custom_root_in_any_spelling_of_the_base_passes_validation(): void
    {
        $this->createSite([
            'domain' => 'spelled.test',
            'include_www' => false,
            'root_path' => 'c:\\users\\first last\\dpanel\\sites\\spelled_test\\public_html',
        ])->assertOk();
    }

    public function test_a_root_outside_the_base_is_rejected(): void
    {
        $this->createSite([
            'domain' => 'outside.test',
            'include_www' => false,
            'root_path' => 'C:\\Windows\\System32',
        ])->assertStatus(422)->assertJsonValidationErrors(['root_path'], 'errors');

        $this->assertSame([], $this->drustCalls('user'));
    }

    public function test_a_refused_account_step_stores_nothing(): void
    {
        $this->createSite(['domain' => 'refused.test', 'include_www' => false], drustAccepts: false)->assertServerError();

        $this->assertFalse(Website::query()->where('domain', 'refused.test')->exists());
    }
}
