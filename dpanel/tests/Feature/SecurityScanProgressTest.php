<?php

namespace Tests\Feature;

use App\Models\SecurityScan;
use App\Services\Security\ScanProgress;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityScanProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_09_28_100000_create_security_center_tables.php'))->up();
        config([
            'serverpanel.execution_api_base_url' => 'http://drust.test',
            'serverpanel.execution_api_token' => 'secret',
        ]);
    }

    public function test_full_scan_halfway_through_clamav(): void
    {
        $scan = $this->scan('full');
        Http::fake(["drust.test/api/v1/security/scan/progress/scan-{$scan->id}" => Http::response([
            'success' => true,
            'data' => ['phase' => 'clamav', 'done' => 500, 'total' => 1000],
        ])]);

        $progress = app(ScanProgress::class)->for($scan);

        // counting 3 + files 40 + integrity 4 done, half of clamav's 48, of 100 in total.
        $this->assertSame(71, $progress['percent']);
        $this->assertSame('ClamAV malware scan', $progress['label']);
        $this->assertSame([500, 1000], [$progress['done'], $progress['total']]);
        $this->assertSame(
            ['done', 'done', 'done', 'active', 'pending'],
            array_column($progress['steps'], 'state'),
        );
    }

    public function test_live_findings_carry_their_score_category_and_cost(): void
    {
        $scan = $this->scan('quick');
        Http::fake(['drust.test/*' => Http::response([
            'success' => true,
            'data' => [
                'phase' => 'files', 'done' => 10, 'total' => 100,
                'found' => ['medium' => 1],
                'recent' => [['rule_id' => 'DP-PERM-001', 'severity' => 'medium', 'category' => 'permissions', 'title' => 'World-writable file', 'file_path' => 'index.php']],
            ],
        ])]);

        $progress = app(ScanProgress::class)->for($scan);

        $this->assertSame(['medium' => 1], $progress['found']);
        // permissions findings count against the PHP & Files score.
        $this->assertSame('PHP & Files', $progress['recent'][0]['category_label']);
        $this->assertSame(8, $progress['recent'][0]['penalty']);
    }

    public function test_quick_scan_has_no_clamav_step_and_shares_the_bar_without_it(): void
    {
        $scan = $this->scan('quick');
        Http::fake(['drust.test/*' => Http::response([
            'success' => true,
            'data' => ['phase' => 'files', 'done' => 250, 'total' => 1000],
        ])]);

        $progress = app(ScanProgress::class)->for($scan);

        $this->assertSame(['counting', 'files', 'integrity', 'saving'], array_column($progress['steps'], 'key'));
        // (3 + 40 * 0.25) / 52
        $this->assertSame(25, $progress['percent']);
    }

    public function test_saving_phase_after_drust_returns(): void
    {
        $scan = $this->scan('quick');
        Http::fake();
        ScanProgress::markSaving($scan);

        $progress = app(ScanProgress::class)->for($scan);

        $this->assertSame('saving', $progress['phase']);
        $this->assertSame(90, $progress['percent']);
        Http::assertNothingSent();
    }

    public function test_drust_unreachable_shows_counting_instead_of_failing(): void
    {
        $scan = $this->scan('full');
        Http::fake(['drust.test/*' => Http::response('down', 500)]);

        $progress = app(ScanProgress::class)->for($scan);

        $this->assertSame('counting', $progress['phase']);
        $this->assertSame(0, $progress['percent']);
    }

    public function test_finished_and_server_scans(): void
    {
        $finished = $this->scan('quick', 'completed');
        $this->assertNull(app(ScanProgress::class)->for($finished));

        $server = SecurityScan::query()->create(['website_id' => null, 'scan_type' => 'configuration', 'status' => 'running', 'started_at' => now()]);
        $progress = app(ScanProgress::class)->for($server);
        $this->assertNull($progress['percent']);
        $this->assertSame('Checking server configuration', $progress['label']);
    }

    private function scan(string $type, string $status = 'running'): SecurityScan
    {
        return SecurityScan::query()->create(['website_id' => 'site-1', 'scan_type' => $type, 'status' => $status, 'started_at' => now()]);
    }
}
