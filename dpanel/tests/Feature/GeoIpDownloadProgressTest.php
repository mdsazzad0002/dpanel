<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LoginAudit\GeoIpDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GeoIpDownloadProgressTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('login-audit:geoip-progress');
        $this->token = str_repeat('b', 64);
        URL::defaults(['token' => $this->token]);
    }

    private function admin()
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $this->withoutMiddleware()->actingAs($admin)->withSession(['panel_session_token' => $this->token]);
    }

    public function test_starting_a_download_reports_queued_and_a_second_start_does_not_queue_again(): void
    {
        Bus::fake();
        $request = $this->admin();

        $request->postJson("/dpt{$this->token}/security/login-audit/geoip")
            ->assertOk()
            ->assertJsonPath('progress.stage', 'queued');

        app(GeoIpDatabase::class)->setProgress('downloading', ['downloaded' => 50, 'total' => 200]);

        $request->postJson("/dpt{$this->token}/security/login-audit/geoip")
            ->assertOk()
            ->assertJsonPath('progress.stage', 'downloading');

        $request->getJson("/dpt{$this->token}/security/login-audit/geoip/status")
            ->assertOk()
            ->assertJsonPath('progress.downloaded', 50)
            ->assertJsonPath('progress.total', 200);
    }

    public function test_failed_download_is_reported(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response('', 404)]);

        $this->artisan('login-audit:geoip-update', ['--force' => true])->assertFailed();

        $progress = app(GeoIpDatabase::class)->progress();
        $this->assertSame('failed', $progress['stage']);
        $this->assertStringContainsString('HTTP 404', $progress['message']);
    }

    public function test_a_stage_without_updates_counts_as_stalled(): void
    {
        $this->travelTo(now()->subMinutes(5));
        app(GeoIpDatabase::class)->setProgress('downloading');
        $this->travelBack();

        $this->assertSame('failed', app(GeoIpDatabase::class)->progress()['stage']);
        $this->assertFalse(app(GeoIpDatabase::class)->isRunning());
    }
}
