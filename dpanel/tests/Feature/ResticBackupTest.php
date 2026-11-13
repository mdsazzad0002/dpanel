<?php

namespace Tests\Feature;

use App\Jobs\RunResticBackupsJob;
use App\Models\Notification;
use App\Models\ResticSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ResticBackupService;
use App\Support\ResticSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog W6: encrypted, incremental restore points with restic. */
class ResticBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);

        return $user;
    }

    private function url(string $path): string
    {
        return "/dpt{$this->token}/backups/incremental{$path}";
    }

    private function website(string $domain, ?User $owner = null): Website
    {
        return Website::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'root_path' => "/home/{$domain}/public_html",
            'site_owner' => str_replace('.', '', $domain), 'php_version' => '8.3', 'status' => 'live', 'type' => 'primary',
            'assigned_user_id' => $owner?->id,
        ]);
    }

    private function configured(): ResticSettings
    {
        $settings = app(ResticSettings::class);
        $settings->write(['repository' => '/var/backups/restic', 'password' => 'the-key', 'env' => [], 'key_confirmed_at' => now()->toIso8601String()]);

        return $settings;
    }

    /** @return array<int, array<string, mixed>> */
    private function sent(string $action): array
    {
        return Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), "/api/v1/restic/{$action}"))->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_a_new_repository_gets_a_new_key_that_is_shown_once_and_kept_encrypted(): void
    {
        Http::fake(['drust.test/api/v1/restic/init' => Http::response(['success' => true, 'data' => ['created' => true]])]);
        $this->as('admin');
        $this->withoutVite();

        $this->post($this->url('/repository'), ['kind' => 'local', 'path' => '/var/backups/restic'])
            ->assertRedirect()->assertSessionHas('restic_new_key');

        $settings = app(ResticSettings::class);
        $key = $settings->read()['password'];
        $this->assertSame(40, strlen($key));
        $this->assertSame('/var/backups/restic', $this->sent('init')[0]['repository']);
        $this->assertSame($key, $this->sent('init')[0]['password']);
        // Never in the database as it is.
        $this->assertStringNotContainsString($key, (string) DB::table('backup_settings')->where('setting_key', 'restic')->value('setting_value'));
        $this->assertTrue($settings->keyAtRisk());

        $this->get($this->url(''))->assertInertia(fn ($page) => $page->component('Backups/Incremental/Index')->where('keyAtRisk', true)->where('newKey', $key));
        $this->get("/dpt{$this->token}/backups")->assertInertia(fn ($page) => $page->where('resticKeyAtRisk', true));

        $download = $this->get($this->url('/key'))->assertOk();
        $this->assertStringContainsString($key, $download->streamedContent());

        $this->post($this->url('/key/confirm'), ['saved' => '1'])->assertRedirect();
        $this->assertFalse($settings->keyAtRisk());
    }

    public function test_an_existing_repository_is_opened_with_its_password_and_read(): void
    {
        $website = $this->website('shop.test');
        Http::fake([
            'drust.test/api/v1/restic/init' => Http::response(['success' => true, 'data' => ['created' => false]]),
            'drust.test/api/v1/restic/snapshots' => Http::response(['success' => true, 'data' => ['snapshots' => [
                ['id' => 'aaa111', 'time' => '2026-11-10T02:30:00Z', 'website_id' => (string) $website->id],
            ]]]),
        ]);
        $this->as('admin');

        $this->post($this->url('/repository'), [
            'kind' => 's3', 'endpoint' => 's3.example.com', 'bucket' => 'backups', 'path' => 'dpanel',
            'access_key' => 'AKIA1', 'secret_key' => 'shh', 'region' => 'eu-1', 'password' => 'old-key',
        ])->assertRedirect()->assertSessionMissing('restic_new_key');

        $init = $this->sent('init')[0];
        $this->assertSame('s3:s3.example.com/backups/dpanel', $init['repository']);
        $this->assertSame(['AWS_ACCESS_KEY_ID' => 'AKIA1', 'AWS_SECRET_ACCESS_KEY' => 'shh', 'AWS_DEFAULT_REGION' => 'eu-1'], (array) $init['env']);
        $this->assertFalse(app(ResticSettings::class)->keyAtRisk());
        $this->assertSame('shh', app(ResticSettings::class)->read()['env']['AWS_SECRET_ACCESS_KEY']);
        $this->assertSame(['aaa111'], ResticSnapshot::query()->pluck('snapshot_id')->all());
    }

    public function test_a_wrong_password_leaves_the_settings_alone(): void
    {
        Http::fake(['drust.test/api/v1/restic/init' => Http::response(['success' => false, 'message' => 'The password does not open this repository.'])]);
        $this->as('admin');

        $this->post($this->url('/repository'), ['kind' => 'local', 'path' => '/var/backups/restic', 'password' => 'nope'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'does not open'));

        $this->assertFalse(app(ResticSettings::class)->configured());
    }

    public function test_a_run_backs_up_each_website_records_its_point_and_goes_on_after_a_failure(): void
    {
        $this->configured();
        $shop = $this->website('shop.test');
        $blog = $this->website('blog.test');
        DB::table('database_requests')->insert([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'database_name' => 'shop_db', 'database_user' => 'shop_u',
            'database_password' => 'pw', 'database_host' => '127.0.0.1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/backup')) {
                return $request['website']['domain'] === 'blog.test'
                    ? Http::response(['success' => false, 'message' => 'restic backup failed: disk full'])
                    : Http::response(['success' => true, 'data' => ['snapshot_id' => 'snap1', 'files_new' => 3, 'data_added' => 2048, 'total_bytes' => 90000]]);
            }

            return Http::response(['success' => true, 'data' => ['removed' => [], 'pruned' => false]]);
        });

        (new RunResticBackupsJob)->handle(app(ResticBackupService::class), app(ResticSettings::class));

        $point = ResticSnapshot::query()->sole();
        $this->assertSame((string) $shop->id, $point->website_id);
        $this->assertSame(2048, $point->data_added);
        $backups = collect($this->sent('backup'))->keyBy(fn ($body) => $body['website']['domain']);
        $this->assertSame('the-key', $backups['shop.test']['password']);
        $this->assertSame([['name' => 'shop_db', 'user' => 'shop_u', 'password' => 'pw', 'host' => '127.0.0.1']], $backups['shop.test']['website']['databases']);
        $this->assertSame(['daily' => 7, 'weekly' => 4, 'monthly' => 6], $this->sent('forget')[0]['keep']);
        $state = app(ResticSettings::class)->read();
        $this->assertSame('failed', $state['last_run_status']);
        $this->assertStringContainsString('blog.test: restic backup failed: disk full', $state['last_run_message']);
        $this->assertTrue(Notification::query()->where('title', 'Incremental backup had failures')->exists());
        $this->assertNotNull($blog);
    }

    public function test_retention_removes_the_points_restic_dropped(): void
    {
        $this->configured();
        $site = $this->website('shop.test');
        foreach (['old1', 'old2', 'keep'] as $id) {
            ResticSnapshot::query()->create(['website_id' => $site->id, 'snapshot_id' => $id, 'taken_at' => now()]);
        }
        Http::fake(['drust.test/api/v1/restic/forget' => Http::response(['success' => true, 'data' => ['removed' => ['old1', 'old2'], 'pruned' => true]])]);

        $this->assertSame(2, app(ResticBackupService::class)->forget());
        $this->assertSame(['keep'], ResticSnapshot::query()->pluck('snapshot_id')->all());
    }

    public function test_a_customer_sees_and_backs_up_only_their_own_websites(): void
    {
        Queue::fake();
        $this->configured();
        $customer = User::factory()->create();
        $mine = $this->website('mine.test', $customer);
        $theirs = $this->website('theirs.test');
        ResticSnapshot::query()->create(['website_id' => $mine->id, 'snapshot_id' => 'm1', 'taken_at' => now()]);
        ResticSnapshot::query()->create(['website_id' => $theirs->id, 'snapshot_id' => 't1', 'taken_at' => now()]);
        $customer->assignRole('general');
        $this->withoutMiddleware()->actingAs($customer)->withSession(['panel_session_token' => $this->token]);
        $this->withoutVite();

        $this->get($this->url(''))->assertOk()->assertInertia(fn ($page) => $page
            ->where('isAdmin', false)->where('setup', null)->where('keyAtRisk', false)
            ->has('websites', 1)->where('websites.0.domain', 'mine.test')->where('websites.0.restore_points.0.id', 'm1'));

        $this->post($this->url('/run'), ['website_id' => $mine->id])->assertRedirect();
        Queue::assertPushed(RunResticBackupsJob::class, fn ($job) => $job->websiteIds === [(string) $mine->id]);
        $this->post($this->url('/run'), ['website_id' => $theirs->id])->assertNotFound();
        $this->post($this->url('/run'))->assertForbidden();
        $this->post($this->url('/repository'), ['kind' => 'local', 'path' => '/x'])->assertForbidden();
        $this->get($this->url('/key'))->assertForbidden();
    }

    public function test_the_schedule_needs_a_repository_and_something_to_keep(): void
    {
        $this->as('admin');
        $schedule = ['enabled' => true, 'schedule_time' => '04:15', 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6];

        $this->patch($this->url('/schedule'), $schedule)->assertSessionHasErrors('enabled');
        $this->configured();
        $this->flushSession();
        $this->withSession(['panel_session_token' => $this->token]);
        $this->patch($this->url('/schedule'), ['keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0] + $schedule)->assertSessionHasErrors('keep_daily');
        $this->flushSession();
        $this->withSession(['panel_session_token' => $this->token]);
        $this->patch($this->url('/schedule'), $schedule)->assertSessionHasNoErrors();

        $state = app(ResticSettings::class)->read();
        $this->assertTrue($state['enabled']);
        $this->assertSame('04:15', $state['schedule_time']);
    }

    public function test_locations_are_written_the_way_restic_reads_them(): void
    {
        $this->assertSame('/srv/restic', ResticBackupService::location('local', ['path' => ' /srv/restic ']));
        $this->assertSame('sftp:backup@203.0.113.9:/srv/restic', ResticBackupService::location('sftp', ['user' => 'backup', 'host' => '203.0.113.9', 'path' => '/srv/restic']));
        $this->assertSame('s3:s3.amazonaws.com/bucket', ResticBackupService::location('s3', ['bucket' => 'bucket']));
        $this->assertSame('rclone:gdrive:dpanel', ResticBackupService::location('rclone', ['remote' => 'gdrive', 'path' => 'dpanel']));
    }
}
