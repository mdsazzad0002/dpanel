<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateWhmcsRequest;
use App\Models\FtpAccount;
use App\Models\Mailbox;
use App\Models\User;
use App\Models\WhmcsAccount;
use App\Models\Website;
use App\Services\AccountSuspensionService;
use App\Services\AccountTerminationService;
use App\Services\Website\WebsiteDeletionFailed;
use App\Services\Website\WebsiteDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountTerminationTest extends TestCase
{
    use RefreshDatabase;

    private function website(User $user, string $type = 'primary'): Website
    {
        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => strtolower(Str::random(8)).'.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'www-data',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => $type,
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);
    }

    public function test_whmcs_terminate_suspends_and_keeps_the_data_for_thirty_days(): void
    {
        $user = User::factory()->create();
        $site = $this->website($user);
        $account = WhmcsAccount::query()->create(['external_id' => 'svc-1', 'user_id' => $user->id, 'status' => 'active']);

        $this->withoutMiddleware(AuthenticateWhmcsRequest::class)
            ->postJson('/api/whmcs/v1/account/terminate', ['external_id' => $account->external_id])
            ->assertOk()
            ->assertJsonPath('account.status', 'terminated');

        $user->refresh();
        $this->assertTrue($user->is_suspended);
        $this->assertSame('suspended', $site->fresh()->serving_state);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $user->purge_after->timestamp, 5);
    }

    public function test_unsuspending_cancels_the_termination(): void
    {
        $user = User::factory()->create();
        app(AccountTerminationService::class)->terminate($user);

        app(AccountSuspensionService::class)->unsuspend($user);

        $this->assertNull($user->fresh()->purge_after);
        $this->assertSame(0, app(AccountTerminationService::class)->purgeDue()['purged']);
        $this->assertNotNull($user->fresh());
    }

    public function test_nothing_is_deleted_before_the_grace_period_ends(): void
    {
        $user = User::factory()->create();
        $this->website($user);
        app(AccountTerminationService::class)->terminate($user);
        $this->mock(WebsiteDeletionService::class)->shouldNotReceive('delete');

        $this->artisan('accounts:purge-terminated')->assertSuccessful();

        $this->assertNotNull($user->fresh());
    }

    public function test_after_the_grace_period_websites_are_archived_then_everything_is_deleted(): void
    {
        config(['serverpanel.execution_api_base_url' => 'http://drust.test']);
        Http::fake(['drust.test/*' => Http::response(['success' => true])]);
        $user = User::factory()->create();
        $primary = $this->website($user);
        $alias = $this->website($user, 'alias');
        FtpAccount::query()->create(['website_id' => $primary->id, 'username' => 'ftp_shop', 'directory' => '/home/shop', 'status' => 'active']);
        $mailbox = Mailbox::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $primary->domain, 'mailbox' => 'info', 'email' => "info@{$primary->domain}",
            'password' => 'x', 'quota_mb' => 100, 'status' => 'active',
        ]);
        $other = User::factory()->create();
        $otherSite = $this->website($other);
        $deleted = [];
        $this->mock(WebsiteDeletionService::class)->shouldReceive('delete')
            ->andReturnUsing(function (Website $website) use (&$deleted) {
                $deleted[] = $website->id;
                $website->delete();

                return [];
            });
        app(AccountTerminationService::class)->terminate($user);
        $this->travel(31)->days();

        $this->artisan('accounts:purge-terminated')->assertSuccessful();

        $this->assertSame([$primary->id], $deleted);
        $this->assertNull($user->fresh());
        $this->assertNull($alias->fresh());
        $this->assertNull($mailbox->fresh());
        $this->assertSame(0, FtpAccount::query()->count());
        $this->assertNotNull($otherSite->fresh());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/ftp-account') && $request['action'] === 'delete' && $request['username'] === 'ftp_shop');
    }

    public function test_a_website_that_cannot_be_archived_keeps_the_account_for_the_next_run(): void
    {
        $user = User::factory()->create();
        $site = $this->website($user);
        $this->mock(WebsiteDeletionService::class)->shouldReceive('delete')
            ->andThrow(new WebsiteDeletionFailed('Website archive failed.', 'archive'));
        app(AccountTerminationService::class)->terminate($user);
        $this->travel(31)->days();

        $this->artisan('accounts:purge-terminated')->assertFailed();

        $this->assertNotNull($user->fresh());
        $this->assertNotNull($site->fresh());
    }
}
