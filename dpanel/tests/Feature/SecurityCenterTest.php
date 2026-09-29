<?php

namespace Tests\Feature;

use App\Models\SecurityFinding;
use App\Models\SecurityRule;
use App\Models\SecurityScan;
use App\Services\Security\SecurityScanService;
use App\Services\Security\SecurityScoreService;
use App\Services\Security\ServerPostureCheck;
use Tests\TestCase;

/**
 * Runs only the Security Center migration: the full migration set needs
 * packages that are not installed in every environment.
 */
class SecurityCenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_09_28_100000_create_security_center_tables.php'))->up();
    }

    public function test_findings_are_deduplicated_and_closed_when_no_longer_reported(): void
    {
        $service = app(SecurityScanService::class);
        $first = $this->scan('site-1', 'quick');

        $result = $service->persist($first, [
            $this->finding('DP-PHP-002', 'critical', 'malware', 'wp-content/x.php'),
            $this->finding('DP-PERM-001', 'medium', 'permissions', 'index.php'),
            $this->finding('DP-INT-001', 'medium', 'integrity', 'app.php'),
        ]);
        $this->assertSame(['new' => 3, 'open' => 3, 'resolved' => 0, 'threats' => 3, 'risk' => 56, 'worst' => 'critical'], $result);

        // Second scan still sees the malware, the permission issue is fixed,
        // and the integrity change is not reported again.
        $second = $this->scan('site-1', 'quick');
        $result = $service->persist($second, [
            $this->finding('DP-PHP-002', 'critical', 'malware', 'wp-content/x.php'),
        ]);

        $this->assertSame(1, $result['open']);
        $this->assertSame(0, $result['new']);
        $this->assertSame(1, $result['resolved']);
        $this->assertSame(3, SecurityFinding::query()->count());
        $this->assertSame('resolved', SecurityFinding::query()->where('rule_id', 'DP-PERM-001')->value('status'));
        $this->assertSame('open', SecurityFinding::query()->where('rule_id', 'DP-INT-001')->value('status'));
        $this->assertSame($second->id, SecurityFinding::query()->where('rule_id', 'DP-PHP-002')->value('scan_id'));
    }

    public function test_ignored_and_disabled_rules_do_not_count(): void
    {
        $service = app(SecurityScanService::class);
        SecurityRule::query()->create(['rule_id' => 'DP-PHP-005', 'name' => 'x', 'category' => 'php', 'severity' => 'low', 'detection_type' => 'content', 'enabled' => false]);

        $service->persist($this->scan('site-1', 'quick'), [$this->finding('DP-PHP-001', 'high', 'php', 'uploads/a.php')]);
        SecurityFinding::query()->update(['status' => 'ignored']);

        $result = $service->persist($this->scan('site-1', 'quick'), [
            $this->finding('DP-PHP-001', 'high', 'php', 'uploads/a.php'),
            $this->finding('DP-PHP-005', 'low', 'php', 'b.php'),
        ]);

        $this->assertSame(0, $result['open']);
        $this->assertSame(0, $result['risk']);
        $this->assertSame('ignored', SecurityFinding::query()->value('status'));
        $this->assertSame(0, SecurityFinding::query()->where('rule_id', 'DP-PHP-005')->count());
    }

    public function test_score_only_weights_measured_categories(): void
    {
        $scores = app(SecurityScoreService::class);
        $this->assertNull($scores->calculate()['overall']);

        $scan = $this->scan('site-1', 'quick');
        $scan->update(['status' => 'completed']);
        app(SecurityScanService::class)->persist($scan, [
            $this->finding('DP-PHP-001', 'high', 'php', 'uploads/a.php'),
            $this->finding('DP-PERM-001', 'medium', 'permissions', 'x'),
        ]);

        $result = $scores->calculate();
        $categories = collect($result['categories'])->keyBy('key');

        // quick measures php (100 - 20 - 8, permissions roll into php) and integrity (100).
        $this->assertSame(72, $categories['php']['score']);
        $this->assertSame(100, $categories['integrity']['score']);
        $this->assertNull($categories['malware']['score']);
        $this->assertTrue($categories['waf']['planned']);
        // (72 * 10 + 100 * 10) / 20
        $this->assertSame(86, $result['overall']);
        $this->assertSame('Good', $result['grade']);
        $this->assertSame(1, $result['counts']['high']);

        // Another user's website does not affect a scoped score.
        $this->assertNull($scores->calculate(['site-2'], false)['overall']);
    }

    public function test_posture_rules_for_ssh_and_exposed_ports(): void
    {
        $posture = app(ServerPostureCheck::class);

        $ssh = $posture->sshFindings(['installed' => true, 'service_active' => true, 'permit_root_login' => 'yes', 'password_authentication' => 'On']);
        $this->assertSame(['DP-SSH-001', 'DP-SSH-002'], array_column($ssh, 'rule_id'));
        $this->assertSame([], $posture->sshFindings(['installed' => true, 'service_active' => true, 'permit_root_login' => 'prohibit-password', 'password_authentication' => 'Off']));

        $ports = [
            ['port' => 3306, 'listening' => true, 'addresses' => ['0.0.0.0'], 'firewall_allowed' => true, 'service' => 'MySQL'],
            ['port' => 6379, 'listening' => true, 'addresses' => ['127.0.0.1'], 'firewall_allowed' => false, 'service' => 'Redis'],
            ['port' => 5432, 'listening' => true, 'addresses' => ['0.0.0.0'], 'firewall_allowed' => false, 'service' => 'PostgreSQL'],
        ];
        $findings = $posture->firewallFindings(['firewall' => ['enabled' => true], 'ports' => $ports]);
        $this->assertSame(['DP-NET-001'], array_column($findings, 'rule_id'));
        $this->assertSame('port:3306', $findings[0]['file_path']);

        $findings = $posture->firewallFindings(['firewall' => ['enabled' => false], 'ports' => $ports]);
        $this->assertSame(['DP-FW-001', 'DP-NET-001', 'DP-NET-001'], array_column($findings, 'rule_id'));
    }

    private function scan(string $websiteId, string $type): SecurityScan
    {
        return SecurityScan::query()->create(['website_id' => $websiteId, 'scan_type' => $type, 'status' => 'running']);
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $ruleId, string $severity, string $category, ?string $path): array
    {
        return ['rule_id' => $ruleId, 'severity' => $severity, 'category' => $category, 'title' => $ruleId, 'file_path' => $path];
    }
}
