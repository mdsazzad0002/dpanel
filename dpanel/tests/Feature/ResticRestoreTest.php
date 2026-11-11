<?php

namespace Tests\Feature;

use App\Jobs\RestoreResticItemJob;
use App\Models\ResticSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\ResticBackupService;
use App\Services\NotificationService;
use App\Support\ResticSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog W7: one file, database or mailbox back from a restore point. */
class ResticRestoreTest extends TestCase
{
    use RefreshDatabase;

    private const SNAP = 'aaaa1111bbbb2222';

    private string $token;

    private User $customer;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('d', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
        app(ResticSettings::class)->write(['repository' => '/var/backups/restic', 'password' => 'the-key', 'env' => [], 'key_confirmed_at' => now()->toIso8601String()]);

        $this->customer = User::factory()->create();
        $this->customer->assignRole('general');
        $this->site = $this->website('shop.test', $this->customer);
        ResticSnapshot::query()->create(['website_id' => $this->site->id, 'snapshot_id' => self::SNAP, 'taken_at' => now()->subDay()]);
        DB::table('database_requests')->insert([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'database_name' => 'shop_db', 'database_user' => 'shop_u',
            'database_password' => 'pw', 'database_host' => '127.0.0.1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('mailboxes')->insert([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'mailbox' => 'info', 'email' => 'info@shop.test', 'password' => 'x',
            'mail_home' => '/home/shoptest/mail/shop.test/info/Maildir', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withoutMiddleware()->actingAs($this->customer)->withSession(['panel_session_token' => $this->token]);
    }

    private function website(string $domain, ?User $owner): Website
    {
        return Website::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'root_path' => "/home/{$domain}/public_html",
            'site_owner' => str_replace('.', '', $domain), 'php_version' => '8.3', 'status' => 'live', 'type' => 'primary',
            'assigned_user_id' => $owner?->id,
        ]);
    }

    private function url(string $suffix = '', ?Website $site = null, string $snapshot = self::SNAP): string
    {
        $site ??= $this->site;

        return "/cpsess{$this->token}/backups/incremental/{$site->id}/{$snapshot}{$suffix}";
    }

    /** @return array<int, array<string, mixed>> */
    private function sent(string $action): array
    {
        return Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), "/api/v1/restic/{$action}"))->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_the_page_offers_the_websites_own_databases_and_mailboxes(): void
    {
        $this->withoutVite();

        $this->get($this->url())->assertOk()->assertInertia(fn ($page) => $page
            ->component('Backups/Incremental/Restore')
            ->where('website.home', '/home/shoptest')
            ->where('point.id', self::SNAP)
            ->where('databases', ['shop_db'])
            ->where('mailboxes.0.path', '/home/shoptest/mail/shop.test/info/Maildir'));
    }

    public function test_only_the_websites_own_restore_points_open(): void
    {
        $other = $this->website('other.test', null);
        ResticSnapshot::query()->create(['website_id' => $other->id, 'snapshot_id' => 'cccc3333dddd4444', 'taken_at' => now()]);

        $this->get($this->url('', $other, 'cccc3333dddd4444'))->assertNotFound();
        $this->get($this->url('', null, 'cccc3333dddd4444'))->assertNotFound();
        $this->postJson($this->url('/restore', $other, 'cccc3333dddd4444'), ['kind' => 'files', 'path' => '/home/othertest'])->assertNotFound();
    }

    public function test_browsing_sends_no_database_passwords(): void
    {
        Http::fake(['drust.test/api/v1/restic/ls' => Http::response(['success' => true, 'data' => ['path' => '/home/shoptest', 'entries' => [['name' => 'site', 'type' => 'dir', 'path' => '/home/shoptest/site']]]])]);

        $this->postJson($this->url('/browse'), ['path' => '/home/shoptest'])->assertOk()->assertJsonPath('entries.0.name', 'site');

        $sent = $this->sent('ls')[0];
        $this->assertSame(self::SNAP, $sent['snapshot_id']);
        $this->assertSame(['id' => (string) $this->site->id, 'site_owner' => 'shoptest', 'databases' => []], $sent['website']);
        $this->assertSame('the-key', $sent['password']);
    }

    public function test_a_restore_runs_in_the_background_and_reports_where_it_went(): void
    {
        Queue::fake();
        $restoreId = $this->postJson($this->url('/restore'), ['kind' => 'files', 'path' => '/home/shoptest/site/wp-config.php'])
            ->assertOk()->json('restore_id');

        Queue::assertPushed(RestoreResticItemJob::class, function (RestoreResticItemJob $job) use (&$pushed) {
            $pushed = $job;

            return $job->item === ['kind' => 'files', 'path' => '/home/shoptest/site/wp-config.php'] && $job->snapshotId === self::SNAP;
        });
        $this->getJson("/cpsess{$this->token}/backups/incremental/restores/{$restoreId}")->assertOk()->assertJsonPath('stage', 'queued')->assertJsonMissingPath('user_id');

        Http::fake(['drust.test/api/v1/restic/restore' => Http::response(['success' => true, 'data' => ['kind' => 'files', 'restored_to' => '/home/shoptest/restored-20261111-120000/wp-config.php']])]);
        $pushed->handle(app(ResticBackupService::class), app(NotificationService::class));

        $this->getJson("/cpsess{$this->token}/backups/incremental/restores/{$restoreId}")
            ->assertJsonPath('stage', 'completed')
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'restored-20261111-120000'));
        $this->assertSame(['kind' => 'files', 'path' => '/home/shoptest/site/wp-config.php'], $this->sent('restore')[0]['item']);

        // Someone else cannot read it.
        $stranger = User::factory()->create();
        $stranger->assignRole('general');
        $this->actingAs($stranger)->getJson("/cpsess{$this->token}/backups/incremental/restores/{$restoreId}")->assertNotFound();
    }

    public function test_a_database_restore_carries_only_its_websites_credentials(): void
    {
        Queue::fake();
        $this->postJson($this->url('/restore'), ['kind' => 'database', 'name' => 'someone_elses'])->assertStatus(422);
        $this->postJson($this->url('/restore'), ['kind' => 'mailbox', 'path' => '/home/othertest/mail/x/Maildir'])->assertStatus(422);
        Queue::assertNothingPushed();

        Http::fake(['drust.test/api/v1/restic/restore' => Http::response(['success' => true, 'data' => ['kind' => 'database', 'database' => 'shop_db', 'before_restore' => '/home/shoptest/restored-x/before-restore/shop_db.sql']])]);
        $result = app(ResticBackupService::class)->restore($this->site, self::SNAP, ['kind' => 'database', 'name' => 'shop_db']);

        $this->assertSame('database', $result['kind']);
        $this->assertSame([['name' => 'shop_db', 'user' => 'shop_u', 'password' => 'pw', 'host' => '127.0.0.1']], $this->sent('restore')[0]['website']['databases']);
    }

    public function test_a_failed_restore_says_why(): void
    {
        Http::fake(['drust.test/api/v1/restic/restore' => Http::response(['success' => false, 'message' => 'The mailbox does not exist now. Create it again, then restore into it.'])]);
        $job = new RestoreResticItemJob('r-1', $this->customer->id, (string) $this->site->id, self::SNAP, ['kind' => 'mailbox', 'path' => '/home/shoptest/mail/shop.test/info/Maildir']);

        $job->handle(app(ResticBackupService::class), app(NotificationService::class));

        $status = RestoreResticItemJob::status('r-1');
        $this->assertSame('failed', $status['stage']);
        $this->assertStringContainsString('Create it again', $status['message']);
    }
}
