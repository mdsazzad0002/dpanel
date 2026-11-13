<?php

namespace Tests\Feature\Mail;

use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\MailboxAutoresponder;
use App\Models\MailForwarder;
use App\Models\PackagePlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Mail\Backend\MailBackend;
use App\Services\Mail\Backend\PostfixDovecotBackend;
use App\Services\ScriptExecutionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog I4, M1, M2, M3, M8: mail through MailBackend. */
class MailForwardingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
        $this->user = User::factory()->create();
        $this->user->assignRole('general');
        $this->site('shop.test', $this->user);
    }

    private function site(string $domain, User $owner): void
    {
        Website::create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner->id,
        ]);
    }

    private function mailbox(string $email, ?User $owner = null): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'mailbox' => $local, 'email' => $email,
            'password' => 'x', 'quota_mb' => 10, 'status' => 'active', 'site_owner' => 'shop',
            'mail_home' => "/home/shop/mail/{$domain}/{$local}/Maildir", 'created_by' => ($owner ?? $this->user)->id,
        ]);
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function url(string $path): string
    {
        return "/dpt{$this->token}{$path}";
    }

    public function test_postfix_and_dovecot_is_the_bound_backend(): void
    {
        $this->assertInstanceOf(PostfixDovecotBackend::class, app(MailBackend::class));
    }

    public function test_a_forwarder_is_made_on_an_own_domain_only(): void
    {
        $this->as($this->user)->postJson($this->url('/emails/forwarders'), [
            'local_part' => 'Sales', 'domain' => 'shop.test', 'destinations' => ['Boss@Example.com', 'boss@example.com', 'team@example.com'],
        ])->assertCreated();

        $forwarder = MailForwarder::query()->sole();
        $this->assertSame('sales@shop.test', $forwarder->address);
        $this->assertSame(['boss@example.com', 'team@example.com'], $forwarder->destinationList());
        $this->assertSame($this->user->id, $forwarder->created_by);

        $this->as($this->user)->postJson($this->url('/emails/forwarders'), [
            'local_part' => 'x', 'domain' => 'other.test', 'destinations' => ['a@example.com'],
        ])->assertJsonValidationErrors('domain');
    }

    public function test_a_forwarder_cannot_loop_shadow_a_mailbox_or_repeat(): void
    {
        $this->mailbox('info@shop.test');
        $post = fn (array $body) => $this->as($this->user)->postJson($this->url('/emails/forwarders'), $body + ['domain' => 'shop.test']);

        $post(['local_part' => 'me', 'destinations' => ['me@shop.test']])->assertJsonValidationErrors('destinations');
        $post(['local_part' => 'info', 'destinations' => ['a@example.com']])->assertJsonValidationErrors('local_part');
        $post(['local_part' => 'ok', 'destinations' => ['a@example.com']])->assertCreated();
        $post(['local_part' => 'ok', 'destinations' => ['b@example.com']])->assertJsonValidationErrors('local_part');
        $post(['local_part' => '-bad', 'destinations' => ['a@example.com']])->assertJsonValidationErrors('local_part');
    }

    public function test_a_package_without_forwarding_refuses_forwarders_and_catch_all(): void
    {
        $plan = PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'No fwd', 'slug' => 'no-fwd', 'max_storage_mb' => 100, 'max_mailboxes' => 10, 'allow_forwarding' => false,
        ]);
        $this->user->forceFill(['package_id' => $plan->id])->save();
        $this->mailbox('info@shop.test');

        $this->as($this->user)->postJson($this->url('/emails/forwarders'), [
            'local_part' => 'sales', 'domain' => 'shop.test', 'destinations' => ['a@example.com'],
        ])->assertJsonValidationErrors('domain');
        $this->as($this->user)->putJson($this->url('/emails/catch-all'), ['domain' => 'shop.test', 'target' => 'a@example.com'])
            ->assertJsonValidationErrors('domain');
    }

    public function test_only_the_owner_sees_and_removes_a_forwarder(): void
    {
        $forwarder = MailForwarder::query()->create(['address' => 'a@shop.test', 'domain' => 'shop.test', 'destinations' => 'x@example.com', 'created_by' => $this->user->id]);
        $stranger = User::factory()->create();
        $stranger->assignRole('general');

        $this->as($stranger)->deleteJson($this->url("/emails/forwarders/{$forwarder->id}"))->assertNotFound();
        $this->as($this->user)->deleteJson($this->url("/emails/forwarders/{$forwarder->id}"))->assertOk();
        $this->assertSame(0, MailForwarder::query()->count());
    }

    public function test_catch_all_needs_a_hosted_domain_and_can_be_cleared(): void
    {
        $this->as($this->user)->putJson($this->url('/emails/catch-all'), ['domain' => 'shop.test', 'target' => 'all@example.com'])
            ->assertJsonValidationErrors('target');

        $this->mailbox('info@shop.test');
        $this->as($this->user)->putJson($this->url('/emails/catch-all'), ['domain' => 'shop.test', 'target' => 'nobody@shop.test'])
            ->assertJsonValidationErrors('target');
        $this->as($this->user)->putJson($this->url('/emails/catch-all'), ['domain' => 'shop.test', 'target' => 'Info@Shop.test'])->assertOk();
        $this->assertSame('info@shop.test', MailDomain::query()->where('domain', 'shop.test')->value('catch_all_to'));

        $this->as($this->user)->putJson($this->url('/emails/catch-all'), ['domain' => 'shop.test', 'target' => null])->assertOk();
        $this->assertNull(MailDomain::query()->where('domain', 'shop.test')->value('catch_all_to'));
    }

    public function test_an_autoresponder_is_hidden_without_sieve(): void
    {
        config(['mail.dpanel_sieve_config' => '/nonexistent/sieve.conf']);
        $mailbox = $this->mailbox('info@shop.test');

        $this->as($this->user)->getJson($this->url("/emails/{$mailbox->id}/autoresponder"))->assertOk()->assertJsonPath('supported', false);
        $this->as($this->user)->putJson($this->url("/emails/{$mailbox->id}/autoresponder"), ['enabled' => true, 'subject' => 'Away', 'body' => 'Back soon'])
            ->assertNotFound();
    }

    public function test_an_autoresponder_reaches_drust_and_is_saved_only_when_drust_takes_it(): void
    {
        $sieve = tempnam(sys_get_temp_dir(), 'sieve');
        config(['mail.dpanel_sieve_config' => $sieve]);
        $mailbox = $this->mailbox('info@shop.test');
        Http::fakeSequence('drust.test/*')
            ->push(['success' => false, 'message' => 'Dates must be YYYY-MM-DD.'])
            ->push(['success' => true])
            ->push(['success' => true]);

        $body = ['enabled' => true, 'subject' => 'Away', 'body' => 'Back soon', 'interval_days' => 3, 'starts_on' => '2026-12-20', 'ends_on' => '2027-01-02'];
        $this->as($this->user)->putJson($this->url("/emails/{$mailbox->id}/autoresponder"), $body)->assertStatus(422);
        $this->assertSame(0, MailboxAutoresponder::query()->count());

        $this->as($this->user)->putJson($this->url("/emails/{$mailbox->id}/autoresponder"), $body)->assertOk();
        Http::assertSent(fn ($request) => $request['action'] === 'autoresponder'
            && $request['mail_home'] === $mailbox->mail_home
            && $request['autoresponder']['email'] === 'info@shop.test'
            && $request['autoresponder']['days'] === 3
            && $request['autoresponder']['ends_on'] === '2027-01-02');
        $this->assertTrue(MailboxAutoresponder::query()->sole()->enabled);

        $this->as($this->user)->putJson($this->url("/emails/{$mailbox->id}/autoresponder"), ['enabled' => false])->assertOk();
        Http::assertSent(fn ($request) => $request['action'] === 'autoresponder' && $request['autoresponder'] === null);
        $this->assertFalse(MailboxAutoresponder::query()->sole()->enabled);
        $this->assertSame('Away', MailboxAutoresponder::query()->sole()->subject, 'turning it off keeps the text');
        @unlink($sieve);
    }

    public function test_the_ends_date_cannot_come_before_the_start_or_hold_a_line_break(): void
    {
        $sieve = tempnam(sys_get_temp_dir(), 'sieve');
        config(['mail.dpanel_sieve_config' => $sieve]);
        $mailbox = $this->mailbox('info@shop.test');

        $this->as($this->user)->putJson($this->url("/emails/{$mailbox->id}/autoresponder"), [
            'enabled' => true, 'subject' => "Away\nBcc: x@y.z", 'body' => 'b', 'starts_on' => '2027-01-02', 'ends_on' => '2026-12-20',
        ])->assertJsonValidationErrors(['subject', 'ends_on']);
        @unlink($sieve);
    }

    public function test_the_relay_password_goes_in_the_environment_not_the_arguments(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $calls = [];
        $gateway = \Mockery::mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('execute')->andReturnUsing(function ($script, $args, $env, $root) use (&$calls) {
            $calls[] = compact('script', 'args', 'env', 'root');

            return ['success' => true, 'output' => "MAIL_RELAY_HOST=smtp.example.com\nMAIL_RELAY_PORT=587\nMAIL_RELAY_USERNAME=me\n"];
        });
        $this->app->instance(ScriptExecutionGateway::class, $gateway);

        $this->as($admin)->putJson($this->url('/emails/smtp-relay'), ['host' => 'SMTP.example.com', 'port' => 587, 'username' => 'me', 'password' => 's3cret pass'])
            ->assertOk()->assertJsonPath('relay.host', 'smtp.example.com')->assertJsonPath('relay.port', 587);

        $this->assertStringEndsWith('/scripts/mail-smtp-relay.sh', $calls[0]['script']);
        $this->assertSame(['--host=smtp.example.com', '--port=587', '--username=me'], $calls[0]['args']);
        $this->assertSame(['DPANEL_RELAY_PASSWORD' => 's3cret pass'], $calls[0]['env']);
        $this->assertTrue($calls[0]['root']);

        $this->as($admin)->deleteJson($this->url('/emails/smtp-relay'))->assertOk();
        $this->assertSame(['--disable'], $calls[2]['args']);
        $this->as($admin)->putJson($this->url('/emails/smtp-relay'), ['host' => 'smtp.example.com', 'port' => 587, 'username' => 'me'])
            ->assertJsonValidationErrors('password');
        $this->as($admin)->putJson($this->url('/emails/smtp-relay'), ['host' => 'bad host;rm', 'port' => 587])
            ->assertJsonValidationErrors('host');
    }

    public function test_a_failed_relay_change_says_why(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $gateway = \Mockery::mock(ScriptExecutionGateway::class);
        $gateway->shouldReceive('execute')->andReturn(['success' => false, 'output' => "[mail-relay] Postfix rejected the change\n"]);
        $this->app->instance(ScriptExecutionGateway::class, $gateway);

        $this->as($admin)->putJson($this->url('/emails/smtp-relay'), ['host' => 'smtp.example.com', 'port' => 465])
            ->assertStatus(422)->assertJsonPath('message', '[mail-relay] Postfix rejected the change');
    }
}
