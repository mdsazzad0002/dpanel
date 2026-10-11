<?php

namespace Tests\Feature\Projects;

use App\Jobs\StartAppProjectJob;
use App\Models\AppProject;
use App\Services\Projects\AppProjectProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartAppProjectJobTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $status): AppProject
    {
        return AppProject::query()->forceCreate([
            'name' => 'pyJob',
            'runtime' => 'python',
            'site_owner' => 'files_katha24_com',
            'working_directory' => '/home/files_katha24_com/public_html/pyJob',
            'entry_file' => 'app:app',
            'version' => '3.10',
            'port' => 18001,
            'status' => $status,
            'unit_key' => 'app-test-'.uniqid(),
        ]);
    }

    public function test_runs_on_the_heavy_queue(): void
    {
        $this->assertSame('heavy', (new StartAppProjectJob(1, 'start'))->queue);
    }

    public function test_starts_with_the_long_provision_timeout(): void
    {
        $project = $this->project('starting');
        $this->mock(AppProjectProcessService::class)
            ->shouldReceive('control')->once()
            ->withArgs(fn ($p, $action, $timeout) => $p->is($project) && $action === 'start' && $timeout === StartAppProjectJob::PROVISION_TIMEOUT)
            ->andReturn([]);

        app()->call([new StartAppProjectJob($project->id, 'start'), 'handle']);
    }

    public function test_records_the_failure_on_the_project(): void
    {
        $project = $this->project('starting');
        $this->mock(AppProjectProcessService::class)
            ->shouldReceive('control')->andThrow(new \RuntimeException('pip install -r requirements.txt failed: boom'));

        app()->call([new StartAppProjectJob($project->id, 'start'), 'handle']);

        $project->refresh();
        $this->assertSame('error', $project->status);
        $this->assertStringContainsString('boom', $project->last_error);
    }

    public function test_skips_a_project_stopped_while_queued(): void
    {
        $project = $this->project('stopped');
        $this->mock(AppProjectProcessService::class)->shouldNotReceive('control');

        app()->call([new StartAppProjectJob($project->id, 'start'), 'handle']);

        $this->assertSame('stopped', $project->refresh()->status);
    }
}
