<?php

namespace Tests\Feature;

use App\Http\Controllers\PanelSearchController;
use App\Models\User;
use App\Services\Security\UploadScanner;
use App\Support\Platform\PlatformCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PlatformCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
        Cache::flush();
    }

    /** @param array<string, mixed> $capabilities */
    private function drustReports(array $capabilities): void
    {
        Http::fake(['drust.test/capabilities' => Http::response(['os' => 'windows', 'profile' => 'server', 'capabilities' => $capabilities])]);
    }

    private function fresh(): PlatformCapabilities
    {
        return new PlatformCapabilities();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin->refresh();
    }

    private function visit(string $path)
    {
        return $this->withoutMiddleware(\App\Http\Middleware\EnsurePanelSessionIsValid::class)
            ->actingAs($this->admin())
            ->withSession(['panel_session_token' => $this->token])
            ->get("/cpsess{$this->token}/{$path}");
    }

    public function test_false_and_none_are_closed_and_everything_else_is_open(): void
    {
        $this->drustReports(['ftp' => false, 'isolation' => 'none', 'mail' => 'stalwart', 'docker' => true]);
        $capabilities = $this->fresh();

        $this->assertFalse($capabilities->allows('ftp'));
        $this->assertFalse($capabilities->allows('isolation'));
        $this->assertTrue($capabilities->allows('mail'));
        $this->assertTrue($capabilities->allows('docker'));
        $this->assertTrue($capabilities->allows('something_new'));
    }

    public function test_the_request_carries_the_drust_token(): void
    {
        $this->drustReports(['ftp' => true]);
        $this->fresh()->report();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_a_closed_capability_is_a_404_and_an_open_one_is_not(): void
    {
        $this->drustReports(['ftp' => false, 'docker' => true]);

        $this->visit('ftp')->assertNotFound();
        $this->visit('ftp/create')->assertNotFound();
        $this->assertNotSame(404, $this->visit('docker')->getStatusCode());
    }

    public function test_every_gated_route_family_has_the_middleware(): void
    {
        $expected = [
            'docker.overview' => 'docker',
            'ftp.store' => 'ftp',
            'websites.ftp.index' => 'ftp',
            'security.firewall' => 'firewall',
            'security.firewall.guide' => 'firewall',
            'websites.terminal.session' => 'terminal',
            'websites.redis-cache.clear' => 'redis',
            'websites.redis-explorer.keys' => 'redis',
            'reseller.panel' => 'reseller',
        ];
        foreach ($expected as $name => $capability) {
            $this->assertContains("capability:{$capability}", Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $this->assertNotContains('capability:redis', collect(Route::getRoutes()->getByName('self-connection.redis.test')->gatherMiddleware())->all());
    }

    public function test_when_drust_gives_no_answer_nothing_closes(): void
    {
        foreach ([
            Http::response('not found', 404),
            Http::response(['success' => false, 'message' => 'Unauthorized'], 400),
            Http::response('<html>', 200),
            fn () => throw new ConnectionException('refused'),
        ] as $answer) {
            Cache::flush();
            Http::fake(['drust.test/capabilities' => $answer]);

            $this->assertSame([], $this->fresh()->report());
            $this->assertTrue($this->fresh()->allows('ftp'));
            $this->assertSame([], $this->fresh()->closedRoutes());
        }
    }

    public function test_a_dead_drust_is_asked_once_not_on_every_request(): void
    {
        Http::fake(['drust.test/capabilities' => Http::response('', 500)]);

        $this->fresh()->report();
        $this->fresh()->report();

        Http::assertSentCount(1);
    }

    public function test_the_answer_is_cached(): void
    {
        $this->drustReports(['ftp' => false]);

        $this->assertFalse($this->fresh()->allows('ftp'));
        $this->assertFalse($this->fresh()->allows('ftp'));

        Http::assertSentCount(1);
    }

    public function test_a_closed_clamav_hides_upload_scanning_and_ignores_a_saved_setting(): void
    {
        $this->drustReports(['clamav' => false]);
        DB::table('security_settings')->updateOrInsert(['setting_key' => 'upload_scan'], ['setting_value' => json_encode(['enabled' => true])]);
        $scanner = app(UploadScanner::class);

        $this->assertContains('security.center.upload-scan', $this->fresh()->closedRoutes());
        $this->assertFalse($scanner->enabled());
        $this->assertNull($scanner->setEnabled(true));
        $scanner->assertClean('/no/such/file', 'shop.zip');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'upload-scan'));
    }

    public function test_the_shared_prop_lists_closed_routes(): void
    {
        $this->drustReports(['ftp' => false, 'docker' => false, 'terminal' => true]);

        $this->visit('dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('platform.os', 'windows')
            ->where('platform.closedRoutes', ['docker.', 'ftp.', 'websites.ftp.']));
    }

    public function test_search_keeps_open_routes(): void
    {
        $this->drustReports(['reseller' => true]);

        $this->assertContains('Reseller Panel', $this->searchLabels());
    }

    public function test_search_leaves_out_closed_routes(): void
    {
        $this->drustReports(['reseller' => false]);

        $this->assertNotContains('Reseller Panel', $this->searchLabels());
    }

    /** @return array<int, string> */
    private function searchLabels(): array
    {
        $admin = $this->admin();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $admin);
        $request->setLaravelSession(app('session.store'));
        session(['panel_session_token' => $this->token]);

        return collect(app(PanelSearchController::class)->buildItems($request))->pluck('label')->all();
    }
}
