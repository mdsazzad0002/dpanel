<?php

namespace Tests\Feature;

use App\Models\CronJob;
use App\Models\User;
use App\Models\Website;
use App\Services\Cron\CronSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class CronJobSuspendedStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        $this->mock(CronSystemService::class)->shouldReceive('sync')->andReturn('ok');
    }

    private function job(bool $ownerSuspended): CronJob
    {
        $owner = User::factory()->create(['is_suspended' => $ownerSuspended]);
        $site = Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner->id,
        ]);

        return CronJob::create([
            'id' => 'job', 'website_id' => $site->id, 'domain' => $site->domain, 'name' => 'job',
            'expression' => '* * * * *', 'command' => 'true', 'status' => $ownerSuspended ? 'suspended' : 'active',
        ]);
    }

    private function edit(CronJob $job, string $status): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->withoutMiddleware()->actingAs($admin)
            ->patch("/cpsess{$this->token}/websites/{$job->website_id}/cron-jobs/{$job->id}", [
                'name' => 'job', 'expression' => '*/5 * * * *', 'command' => 'true', 'status' => $status,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_editing_a_suspended_job_keeps_it_suspended(): void
    {
        $job = $this->job(ownerSuspended: true);

        $this->edit($job, 'suspended');

        $this->assertSame('suspended', $job->fresh()->status);
        $this->assertSame('*/5 * * * *', $job->fresh()->expression);
    }

    public function test_turning_on_a_job_of_a_suspended_owner_waits_for_unsuspension(): void
    {
        $job = $this->job(ownerSuspended: true);

        $this->edit($job, 'active');

        $this->assertSame('suspended', $job->fresh()->status);
    }

    public function test_turning_off_a_suspended_job_keeps_it_off_after_unsuspension(): void
    {
        $job = $this->job(ownerSuspended: true);

        $this->edit($job, 'disabled');

        $this->assertSame('disabled', $job->fresh()->status);
    }

    public function test_without_a_suspension_the_job_runs(): void
    {
        $job = $this->job(ownerSuspended: false);

        $this->edit($job, 'suspended');

        $this->assertSame('active', $job->fresh()->status);
    }
}
