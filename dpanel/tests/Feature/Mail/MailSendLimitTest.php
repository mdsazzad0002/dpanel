<?php

namespace Tests\Feature\Mail;

use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\Notification;
use App\Models\PackagePlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Mail\SendLimits\MailSendCounters;
use App\Services\Mail\SendLimits\MailSendLimitService;
use App\Services\ScriptExecutionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog P5: outbound mail limits and the mail queue. */
class MailSendLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /** @var list<array{script: string, args: array, env: array, root: bool}> */
    private array $calls = [];

    /** @var array<string, array{hour: int, day: int, hit: string|null}>|null */
    private ?array $counts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        Carbon::setTestNow('2026-11-09 10:30:00');

        $gateway = \Mockery::mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('execute')->andReturnUsing(function ($script, $args, $env, $root) {
            $this->calls[] = compact('script', 'args', 'env', 'root');
            if (str_ends_with($script, 'mail-queue.sh')) {
                return ['success' => true, 'output' => 'MAIL_QUEUE_DELETED='.count(array_filter($args, fn ($a) => str_starts_with($a, '--delete=')))."\n"];
            }

            return ['success' => true, 'output' => "MAIL_LIMITS_READY=1\nMAIL_LIMITS_REASON=\nMAIL_LIMITS_CHANGED=1\n"];
        });
        $this->app->instance(ScriptExecutionGateway::class, $gateway);

        $counters = \Mockery::mock(MailSendCounters::class);
        $counters->shouldReceive('read')->andReturnUsing(fn () => $this->counts);
        $this->app->instance(MailSendCounters::class, $counters);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(int $perHour, int $perDay): PackagePlan
    {
        return PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => "P{$perHour}-{$perDay}", 'slug' => Str::random(8), 'max_storage_mb' => 100,
            'max_mailboxes' => 5, 'max_emails_per_hour' => $perHour, 'max_emails_per_day' => $perDay,
        ]);
    }

    private function account(PackagePlan $plan, string $domain, string $owner): User
    {
        $user = User::factory()->create(['package_id' => $plan->id]);
        $site = Website::create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/public_html", 'site_owner' => $owner,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);
        Website::create([
            'id' => (string) Str::uuid(), 'domain' => "www.{$domain}", 'hostname' => "www.{$domain}",
            'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/public_html", 'site_owner' => $owner,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'alias',
            'ssl_mode' => 'none', 'parent_id' => $site->id,
        ]);
        Mailbox::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'mailbox' => 'info', 'email' => "info@{$domain}",
            'password' => 'x', 'quota_mb' => 10, 'status' => 'active', 'site_owner' => $owner,
            'mail_home' => "/home/{$owner}/mail/{$domain}/info/Maildir", 'created_by' => $user->id,
        ]);

        return $user;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function url(string $path): string
    {
        return "/dpt{$this->token}{$path}";
    }

    public function test_the_maps_charge_mailboxes_domains_aliases_and_site_users_to_the_account(): void
    {
        $user = $this->account($this->plan(100, 500), 'Shop.test', 'shop');
        MailDomain::query()->forceCreate(['id' => (string) Str::uuid(), 'domain' => 'mail-only.test', 'assigned_user_id' => $user->id]);
        $this->account($this->plan(0, 0), 'free.test', 'free');

        $maps = app(MailSendLimitService::class)->maps();

        $a = "a{$user->id}";
        $this->assertSame(implode("\n", [
            "info@shop.test {$a}",
            "mail-only.test {$a}",
            "shop.test {$a}",
            "user:shop {$a}",
            "www.shop.test {$a}",
        ]), $maps['senders']);
        $this->assertSame("{$a} 100:500", $maps['limits']);
    }

    public function test_odd_rows_are_left_out_instead_of_breaking_the_map(): void
    {
        $user = $this->account($this->plan(10, 0), 'shop.test', 'shop');
        Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'bad domain;rm', 'hostname' => 'x', 'project_root' => '/x',
            'root_path' => '/x', 'site_owner' => 'Bad User!', 'php_version' => '8.3', 'enable_ssl' => false,
            'status' => 'live', 'type' => 'primary', 'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);

        $senders = app(MailSendLimitService::class)->maps()['senders'];

        $this->assertStringNotContainsString('bad', $senders);
        $this->assertStringContainsString("shop.test a{$user->id}", $senders);
    }

    public function test_sync_writes_through_the_root_script_only_when_the_limits_changed(): void
    {
        $user = $this->account($this->plan(100, 0), 'shop.test', 'shop');
        $service = app(MailSendLimitService::class);

        $this->assertTrue($service->sync()['changed']);
        $this->assertStringEndsWith('/scripts/mail-send-limits.sh', $this->calls[0]['script']);
        $this->assertSame(['--apply'], $this->calls[0]['args']);
        $this->assertTrue($this->calls[0]['root']);
        $this->assertSame("a{$user->id} 100:0", $this->calls[0]['env']['DPANEL_MAIL_LIMITS']);
        $this->assertStringContainsString("user:shop a{$user->id}", $this->calls[0]['env']['DPANEL_MAIL_SENDERS']);

        $this->assertFalse($service->sync()['changed']);
        $this->assertCount(1, $this->calls);

        $user->package->update(['max_emails_per_hour' => 50]);
        $this->assertTrue($service->sync()['changed']);
        $this->assertSame("a{$user->id} 50:0", $this->calls[1]['env']['DPANEL_MAIL_LIMITS']);
    }

    public function test_an_account_over_its_limit_is_alerted_once_per_window(): void
    {
        $user = $this->account($this->plan(100, 0), 'shop.test', 'shop');
        $a = "a{$user->id}";
        $service = app(MailSendLimitService::class);

        $this->counts = [$a => ['hour' => 100, 'day' => 100, 'hit' => 'h:2026110910']];
        $this->assertSame(1, $service->alert());
        $this->assertSame(0, $service->alert());

        $notice = Notification::query()->where('type', 'mail_send_limit')->sole();
        $this->assertSame($user->id, (int) $notice->assigned_user_id);
        $this->assertStringContainsString('100 emails per hour', $notice->message);

        // The next hour's refusal is a new alert.
        Carbon::setTestNow('2026-11-09 11:05:00');
        $this->counts = [$a => ['hour' => 100, 'day' => 200, 'hit' => 'h:2026110911']];
        $this->assertSame(1, $service->alert());
    }

    public function test_a_refusal_from_an_earlier_window_is_not_alerted(): void
    {
        $user = $this->account($this->plan(100, 1000), 'shop.test', 'shop');
        $this->counts = ["a{$user->id}" => ['hour' => 3, 'day' => 3, 'hit' => 'd:20261108']];

        $this->assertSame(0, app(MailSendLimitService::class)->alert());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_the_command_is_quiet_without_rspamd_when_no_package_sets_a_limit(): void
    {
        $gateway = \Mockery::mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('execute')->andReturn(['success' => false, 'output' => '[mail-limits] Rspamd is not installed.']);
        $this->app->instance(ScriptExecutionGateway::class, $gateway);

        $this->artisan('mail:send-limits')->assertSuccessful();

        $this->account($this->plan(10, 0), 'shop.test', 'shop');
        $this->artisan('mail:send-limits')->expectsOutputToContain('Rspamd is not installed')->assertFailed();
    }

    public function test_admins_see_usage_against_the_limit(): void
    {
        $user = $this->account($this->plan(100, 1000), 'shop.test', 'shop');
        $this->counts = ["a{$user->id}" => ['hour' => 100, 'day' => 340, 'hit' => 'h:2026110910']];

        $this->as($this->admin())->getJson($this->url('/mail-health/send-limits'))
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('counters', true)
            ->assertJsonPath('accounts.0.hour', 100)
            ->assertJsonPath('accounts.0.day', 340)
            ->assertJsonPath('accounts.0.per_day', 1000)
            ->assertJsonPath('accounts.0.held', true);

        $this->as($this->admin())->postJson($this->url('/mail-health/send-limits/apply'))
            ->assertOk()->assertJsonPath('message', 'Mail limits applied.');
    }

    public function test_queued_mail_is_deleted_by_id_through_the_root_script(): void
    {
        $this->as($this->admin())->deleteJson($this->url('/mail-health/queue'), ['ids' => ['4F2A1B3C9D', '4F2A1B3C9E']])
            ->assertOk()->assertJsonPath('deleted', 2);

        $this->assertStringEndsWith('/scripts/mail-queue.sh', $this->calls[0]['script']);
        $this->assertSame(['--delete=4F2A1B3C9D', '--delete=4F2A1B3C9E'], $this->calls[0]['args']);
        $this->assertTrue($this->calls[0]['root']);

        $this->as($this->admin())->deleteJson($this->url('/mail-health/queue'), ['ids' => ['ALL; rm -rf /']])
            ->assertJsonValidationErrors('ids.0');
        $this->as($this->admin())->postJson($this->url('/mail-health/queue/retry'))->assertOk();
        $this->assertSame(['--retry'], $this->calls[1]['args']);
    }

    public function test_only_admins_change_the_queue_or_see_the_limits(): void
    {
        $user = User::factory()->create();
        $user->assignRole('reseller');

        $this->withoutMiddleware(\App\Http\Middleware\EnsurePanelSessionIsValid::class)
            ->actingAs($user)->withSession(['panel_session_token' => $this->token]);
        $this->deleteJson($this->url('/mail-health/queue'), ['ids' => ['4F2A1B3C9D']])
            ->assertForbidden();
        $this->getJson($this->url('/mail-health/send-limits'))
            ->assertForbidden();
        $this->assertSame([], $this->calls);
    }

    public function test_a_package_saves_its_mail_limits(): void
    {
        $plan = $this->plan(0, 0);

        $this->as($this->admin())->patch($this->url("/packages/{$plan->id}"), [
            'name' => $plan->name, 'max_storage_mb' => 100, 'max_mailboxes' => 5, 'max_websites' => 1,
            'max_databases' => 1, 'max_bandwidth_gb' => 0, 'max_emails_per_hour' => 200, 'max_emails_per_day' => 1000,
        ])->assertRedirect();

        $plan->refresh();
        $this->assertSame(200, $plan->max_emails_per_hour);
        $this->assertSame(1000, $plan->max_emails_per_day);
    }
}
