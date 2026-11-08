<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Services\PostgresqlServiceManager;
use App\Services\Platform\SystemOverviewClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Backlog D3: the machine's numbers and service states come from drust. */
class SystemOverviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
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
}
