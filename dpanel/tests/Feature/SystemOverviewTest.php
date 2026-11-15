<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\Website\WebsiteController;
use App\Services\Mcp\Tools\PanelOverviewTool;
use App\Services\PostgresqlServiceManager;
use App\Services\Platform\SystemOverviewClient;
use App\Support\Platform\LocalCommand;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Backlog D3: the machine's numbers and service states come from drust. */
class SystemOverviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
    }

    public function test_the_overview_asks_drust_for_the_named_services(): void
    {
        Http::fake(['drust.test/system/overview*' => Http::response(['success' => true, 'overview' => ['hostname' => 'web1', 'services' => ['postfix' => 'running']]])]);

        $overview = app(SystemOverviewClient::class)->overview(['postfix', 'dovecot']);

        $this->assertSame('web1', $overview['hostname']);
        Http::assertSent(fn ($request) => $request['services'] === 'dovecot,postfix');
    }

    public function test_no_answer_from_drust_is_null_not_an_error(): void
    {
        Http::fake(['drust.test/*' => Http::response('down', 502)]);

        $this->assertNull(app(SystemOverviewClient::class)->overview(['x']));
    }

    public function test_the_snapshot_turns_drusts_numbers_into_the_dashboards(): void
    {
        $snapshot = DashboardController::systemSnapshot([
            'cpu_cores' => 4,
            'load' => [2.0, 1.0, 0.5],
            'memory' => ['total_kb' => 8 * 1024 * 1024, 'available_kb' => 2 * 1024 * 1024],
            'disk' => ['total_bytes' => 100 * 1024 ** 3, 'free_bytes' => 40 * 1024 ** 3],
        ]);

        $this->assertSame(50.0, $snapshot['cpu_load_percent']);
        $this->assertSame(6 * 1024, $snapshot['memory_used_mb']);
        $this->assertSame(8 * 1024, $snapshot['memory_total_mb']);
        $this->assertSame(60.0, $snapshot['disk_used_gb']);
        $this->assertSame(100.0, $snapshot['disk_total_gb']);
        $this->assertSame(0.0, DashboardController::systemSnapshot([])['cpu_load_percent']);
        $this->assertSame('1d 2h 3m', DashboardController::formatDuration(86400 + 7200 + 180));
    }

    public function test_a_stacks_service_states_come_from_drust_in_one_call(): void
    {
        Http::fake(['drust.test/system/services*' => Http::response(['success' => true, 'services' => [
            'postgresql' => ['installed' => true, 'active' => true, 'enabled' => true, 'state' => 'active'],
        ]])]);

        $statuses = app(PostgresqlServiceManager::class)->statuses();

        $this->assertNotEmpty($statuses);
        Http::assertSentCount(1);
        foreach ($statuses as $status) {
            $this->assertArrayHasKey('state', $status);
            $this->assertNotSame('', $status['state']);
        }
    }

    /** What drust sends from a Windows PC (D4): memory, uptime and cores, no load. */
    private function windowsHost(): void
    {
        Http::fake(['drust.test/system/overview*' => Http::response(['success' => true, 'overview' => [
            'os' => 'windows', 'cpu_cores' => 8, 'uptime_seconds' => 90061, 'load' => null,
            'memory' => ['total_kb' => 16 * 1024 * 1024, 'available_kb' => 4 * 1024 * 1024, 'swap_total_kb' => 0, 'swap_free_kb' => 0],
        ]])]);
        $this->app->instance(LocalCommand::class, new LocalCommand('Windows'));
    }

    private function invokeHidden(object $object, string $method): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object);
    }

    public function test_the_mcp_overview_takes_the_hosts_figures_from_drust(): void
    {
        $this->windowsHost();
        $tool = app(PanelOverviewTool::class);

        $this->assertSame(8, $this->invokeHidden($tool, 'cpuCores'));
        $this->assertSame('1d 1h 1m', $this->invokeHidden($tool, 'uptime'));
        $this->assertSame(['total_mb' => 16384, 'available_mb' => 4096, 'used_percent' => 75.0], $this->invokeHidden($tool, 'memory'));
    }

    public function test_the_website_page_takes_cpu_and_ram_from_drust(): void
    {
        $this->windowsHost();
        $controller = app(WebsiteController::class);

        $this->assertSame(12288, $this->invokeHidden($controller, 'currentRamUsageMb'));
        // Windows has no load average: unknown is 0, as before.
        $this->assertSame(0.0, $this->invokeHidden($controller, 'currentCpuUsagePercent'));
    }

    public function test_linux_only_monitoring_readers_stay_quiet_elsewhere(): void
    {
        $this->windowsHost();
        $controller = app(MonitoringController::class);

        $this->assertSame([], $this->invokeHidden($controller, 'physicalDisks'));
        $this->assertSame([], $this->invokeHidden($controller, 'networkInterfaces'));
        $this->assertSame([], $this->invokeHidden($controller, 'connectionBreakdown'));
        $this->assertSame([null], array_values(array_unique($this->invokeHidden($controller, 'connectionStats'))));
    }
}
