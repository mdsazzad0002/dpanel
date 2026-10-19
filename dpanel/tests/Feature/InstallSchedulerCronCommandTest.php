<?php

namespace Tests\Feature;

use App\Models\CronJob;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstallSchedulerCronCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['serverpanel.execution_api_base_url' => 'http://drust.test']);
        Http::fake(['drust.test/*' => Http::response(['success' => true, 'message' => 'System cron job panel-scheduler installed.'])]);
        Website::query()->forceCreate([
            'id' => '1',
            'domain' => 'panel.example.test',
            'root_path' => '/var/www/dpanel/public',
            'site_owner' => 'system',
            'php_version' => '8.3',
            'status' => 'live',
        ]);
    }

    public function test_it_lists_the_scheduler_under_the_root_panel_and_runs_it_as_www_data(): void
    {
        $this->artisan('serverpanel:install-scheduler-cron')->assertSuccessful();

        $job = CronJob::query()->findOrFail('panel-scheduler');
        $this->assertSame('1', (string) $job->website_id);
        $this->assertSame('* * * * *', $job->expression);
        $this->assertSame('active', $job->status);
        $this->assertStringContainsString('artisan schedule:run', $job->command);

        Http::assertSent(fn (Request $request): bool => $request['id'] === 'panel-scheduler'
            && $request['user'] === 'www-data'
            && $request['enabled'] === true);
    }

    public function test_a_rerun_keeps_an_admin_disabled_scheduler_disabled(): void
    {
        $this->artisan('serverpanel:install-scheduler-cron')->assertSuccessful();
        CronJob::query()->whereKey('panel-scheduler')->update(['status' => 'disabled', 'expression' => '*/5 * * * *']);

        $this->artisan('serverpanel:install-scheduler-cron')->assertSuccessful();

        $job = CronJob::query()->findOrFail('panel-scheduler');
        $this->assertSame('disabled', $job->status);
        $this->assertSame('*/5 * * * *', $job->expression);
    }
}
