<?php

namespace Tests\Feature;

use App\Jobs\RunResticBackupsJob;
use App\Jobs\RunResticRestoreTestJob;
use App\Models\Notification;
use App\Models\ResticRestoreTest;
use App\Models\ResticSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ResticBackupService;
use App\Support\ResticSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog W8: the restore test, run weekly and kept as a report. */
class ResticRestoreTestTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
        app(ResticSettings::class)->write(['repository' => '/var/backups/restic', 'password' => 'the-key', 'env' => [], 'key_confirmed_at' => now()->toIso8601String()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function website(string $domain): Website
    {
        return Website::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'root_path' => "/home/{$domain}/public_html",
            'site_owner' => str_replace('.', '', $domain), 'php_version' => '8.3', 'status' => 'live', 'type' => 'primary',
        ]);
    }

    private function point(Website $website, string $id, Carbon $at): void
    {
        ResticSnapshot::query()->create(['website_id' => $website->id, 'snapshot_id' => $id, 'taken_at' => $at]);
    }

    /** @return array<int, array<string, mixed>> */
    private function sent(string $action): array
    {
        return Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), "/api/v1/restic/{$action}"))->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    /** drust answering: the check, and each restore point's read-back by id. */
    private function drust(array $check, array $verify): void
    {
        Http::fake(function (Request $request) use ($check, $verify) {
            if (str_ends_with($request->url(), '/check')) {
                return Http::response(['success' => true, 'data' => $check]);
            }
            $answer = $verify[$request['snapshot_id']] ?? null;

            return is_array($answer)
                ? Http::response(['success' => true, 'data' => $answer])
                : Http::response(['success' => false, 'message' => (string) $answer]);
        });
    }

    private function whole(string $id): array
    {
        return [
            'snapshot_id' => $id, 'ok' => true, 'files' => 120, 'bytes' => 900000, 'too_large_to_sample' => 0,
            'sampled' => array_fill(0, 20, ['path' => '/home/x/f', 'size' => 1, 'ok' => true, 'error' => null]),
            'databases' => [['name' => 'shop_db', 'size' => 2048, 'ok' => true, 'error' => null]],
        ];
    }

    private function runTest(): ResticRestoreTest
    {
        (new RunResticRestoreTestJob('manual'))->handle(app(ResticBackupService::class), app(ResticSettings::class));

        return ResticRestoreTest::query()->latest('id')->firstOrFail();
    }

    public function test_the_newest_point_of_every_website_is_read_back_and_a_site_without_one_fails(): void
    {
        $shop = $this->website('shop.test');
        $this->website('blog.test');
        DB::table('database_requests')->insert([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'database_name' => 'shop_db', 'database_user' => 'shop_u',
            'database_password' => 'pw', 'database_host' => '127.0.0.1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->point($shop, 'aaaa0000old', now()->subDays(2));
        $this->point($shop, 'bbbb1111new', now()->subHour());
        $this->drust(['healthy' => true, 'message' => 'No errors were found.'], ['bbbb1111new' => $this->whole('bbbb1111new')]);

        $test = $this->runTest();

        $check = $this->sent('check')[0];
        $this->assertSame(5, $check['read_data_percent']);
        $this->assertSame('the-key', $check['password']);
        $verify = $this->sent('verify');
        $this->assertCount(1, $verify);
        $this->assertSame('bbbb1111new', $verify[0]['snapshot_id']);
        $this->assertSame(20, $verify[0]['sample']);
        // Reading needs no database login.
        $this->assertSame([], $verify[0]['website']['databases']);

        $this->assertSame('failed', $test->status);
        $this->assertTrue($test->repository_healthy);
        $this->assertSame(2, $test->websites_tested);
        $this->assertSame(1, $test->websites_failed);
        $results = collect($test->results)->keyBy('domain');
        $this->assertTrue($results['shop.test']['ok']);
        $this->assertSame(20, $results['shop.test']['files_read']);
        $this->assertSame('missing', $results['blog.test']['problem']);
        $this->assertNotNull($test->finished_at);
        $this->assertTrue(Notification::query()->where('title', 'Restore test failed')->where('message', 'like', '%blog.test: There is no restore point%')->exists());
    }

    public function test_a_damaged_repository_dump_or_unreadable_point_fails_the_test(): void
    {
        $shop = $this->website('shop.test');
        $blog = $this->website('blog.test');
        $this->point($shop, 'cccc2222', now());
        $this->point($blog, 'dddd3333', now());
        $damaged = $this->whole('cccc2222');
        $damaged['ok'] = false;
        $damaged['databases'][0] = ['name' => 'shop_db', 'size' => 2048, 'ok' => false, 'error' => 'the dump does not end like a finished mysqldump; it may be cut short'];
        $this->drust(
            ['healthy' => false, 'message' => 'Fatal: repository contains errors'],
            ['cccc2222' => $damaged, 'dddd3333' => 'restic failed: ciphertext verification failed'],
        );

        $test = $this->runTest();

        $this->assertSame('failed', $test->status);
        $this->assertFalse($test->repository_healthy);
        $this->assertSame(2, $test->websites_failed);
        $results = collect($test->results)->keyBy('domain');
        $this->assertSame('damaged', $results['shop.test']['problem']);
        $this->assertStringContainsString('database shop_db: the dump does not end', $results['shop.test']['error']);
        $this->assertSame('unreadable', $results['blog.test']['problem']);
        $this->assertStringContainsString('ciphertext', $results['blog.test']['error']);
        $this->assertStringContainsString('repository check: Fatal: repository contains errors', $test->message);
    }

    public function test_a_pass_tells_nobody_and_an_old_newest_point_fails_while_backups_are_scheduled(): void
    {
        $shop = $this->website('shop.test');
        $this->point($shop, 'eeee4444', now()->subDays(3));
        $this->drust(['healthy' => true, 'message' => 'No errors were found.'], ['eeee4444' => $this->whole('eeee4444')]);

        $this->assertSame('passed', $this->runTest()->status);
        $this->assertFalse(Notification::query()->where('title', 'Restore test failed')->exists());

        app(ResticSettings::class)->write(['enabled' => true]);
        $test = $this->runTest();
        $this->assertSame('failed', $test->status);
        $this->assertSame('stale', $test->results[0]['problem']);
    }

    public function test_only_the_last_reports_are_kept(): void
    {
        foreach (range(1, RunResticRestoreTestJob::KEEP + 3) as $day) {
            ResticRestoreTest::query()->create(['trigger' => 'schedule', 'status' => 'passed', 'started_at' => now()->subDays(200 - $day)]);
        }
        $this->drust(['healthy' => true, 'message' => 'ok'], []);

        $latest = $this->runTest();

        $this->assertSame(RunResticRestoreTestJob::KEEP, ResticRestoreTest::query()->count());
        $this->assertTrue(ResticRestoreTest::query()->whereKey($latest->id)->exists());
    }

    public function test_the_weekly_test_follows_the_full_backup_on_its_day_only(): void
    {
        Queue::fake();
        Http::fake(['drust.test/api/v1/restic/forget' => Http::response(['success' => true, 'data' => ['removed' => [], 'pruned' => false]])]);
        $backup = fn (?array $ids = null) => (new RunResticBackupsJob($ids))->handle(app(ResticBackupService::class), app(ResticSettings::class));

        Carbon::setTestNow(Carbon::parse('2026-11-15 03:00')); // a Sunday, the default day
        $backup();
        Queue::assertPushed(RunResticRestoreTestJob::class, fn ($job) => $job->trigger === 'schedule');

        Queue::fake();
        $backup(['some-site']);
        Queue::assertNotPushed(RunResticRestoreTestJob::class);

        Carbon::setTestNow(Carbon::parse('2026-11-16 03:00'));
        $backup();
        Queue::assertNotPushed(RunResticRestoreTestJob::class);

        app(ResticSettings::class)->write(['restore_test_day' => 1]);
        $backup();
        Queue::assertPushed(RunResticRestoreTestJob::class);

        Queue::fake();
        app(ResticSettings::class)->write(['restore_test_enabled' => false]);
        $backup();
        Queue::assertNotPushed(RunResticRestoreTestJob::class);
    }

    public function test_admins_start_a_test_but_never_beside_a_backup_or_another_test(): void
    {
        Queue::fake();
        $this->as('admin');
        $this->withoutVite();

        $this->get($this->url(''))->assertOk()->assertInertia(fn ($page) => $page
            ->where('restoreTest.enabled', true)->where('restoreTest.day', 0)->where('restoreTest.scheduled', false)->has('restoreTest.runs', 0));

        $this->post($this->url('/test'))->assertRedirect()->assertSessionHas('success');
        Queue::assertPushed(RunResticRestoreTestJob::class, fn ($job) => $job->trigger === 'manual');

        Queue::fake();
        app(ResticSettings::class)->write(['last_run_status' => 'running', 'last_run_at' => now()->subHour()->toIso8601String()]);
        $this->post($this->url('/test'))->assertSessionHas('error');
        Queue::assertNotPushed(RunResticRestoreTestJob::class);

        // A worker that died a day ago does not block forever.
        app(ResticSettings::class)->write(['last_run_status' => 'running', 'last_run_at' => now()->subDays(2)->toIso8601String()]);
        $this->post($this->url('/test'))->assertSessionHas('success');

        Queue::fake();
        $running = ResticRestoreTest::query()->create(['trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);
        $this->post($this->url('/test'))->assertSessionHas('error');
        $this->post($this->url('/run'))->assertSessionHas('error');
        Queue::assertNothingPushed();
        $this->get($this->url(''))->assertInertia(fn ($page) => $page->where('restoreTest.runs.0.id', $running->id)->where('restoreTest.runs.0.status', 'running'));

        $this->patch($this->url('/test'), ['restore_test_enabled' => true, 'restore_test_day' => 7, 'restore_test_percent' => 5, 'restore_test_sample' => 20])->assertSessionHasErrors('restore_test_day');
        $this->flushSession();
        $this->withSession(['panel_session_token' => $this->token]);
        $this->patch($this->url('/test'), ['restore_test_enabled' => true, 'restore_test_day' => 3, 'restore_test_percent' => 10, 'restore_test_sample' => 50])->assertSessionHasNoErrors();
        $state = app(ResticSettings::class)->read();
        $this->assertSame([3, 10, 50], [$state['restore_test_day'], $state['restore_test_percent'], $state['restore_test_sample']]);
    }

    public function test_customers_neither_see_nor_start_the_restore_test(): void
    {
        Queue::fake();
        $this->as('general');
        $this->withoutVite();

        $this->get($this->url(''))->assertOk()->assertInertia(fn ($page) => $page->where('restoreTest', null));
        $this->post($this->url('/test'))->assertForbidden();
        $this->patch($this->url('/test'), ['restore_test_enabled' => false, 'restore_test_day' => 0, 'restore_test_percent' => 0, 'restore_test_sample' => 1])->assertForbidden();
        Queue::assertNothingPushed();
    }
}
