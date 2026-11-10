<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\SslCertificate;
use App\Models\User;
use App\Models\Website;
use App\Services\Ssl\CustomCertificateExpiryAlert;
use App\Services\Ssl\SslLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog M5: a certificate the customer uploads. */
class CustomCertificateTest extends TestCase
{
    use RefreshDatabase;

    private const CERTIFICATE = "-----BEGIN CERTIFICATE-----\nleaf\n-----END CERTIFICATE-----";

    private const KEY = "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----";

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('b', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
    }

    private function website(User $owner, string $domain = 'shop.example.com', array $extra = []): Website
    {
        return Website::create($extra + [
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $owner->id,
        ]);
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }

    private function user(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        if ($role !== '') {
            $user->assignRole($role);
        }

        return $user;
    }

    /** What drust answers once it has accepted or still serves an uploaded certificate. */
    private function custom(string $expiresAt, array $extra = []): array
    {
        return ['success' => true, 'data' => $extra + [
            'status' => 'valid', 'custom' => true, 'issued' => false, 'renewed' => false,
            'certificate_path' => '/etc/drust/tls/custom/shop.example.com.crt',
            'private_key_path' => '/etc/drust/tls/custom/shop.example.com.key',
            'expires_at' => $expiresAt,
        ]];
    }

    private function upload(Website $site, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/cpsess{$this->token}/websites/{$site->id}/ssl/custom", $body);
    }

    public function test_an_upload_goes_to_drust_and_turns_ssl_on_without_keeping_the_key(): void
    {
        Http::fake(['drust.test/api/v1/ssl/install' => Http::response($this->custom('2027-03-01T00:00:00+00:00', ['issued' => true, 'names' => ['shop.example.com']]))]);
        $site = $this->website($admin = $this->user());

        $this->as($admin);
        $this->upload($site, ['certificate' => self::CERTIFICATE, 'private_key' => self::KEY, 'chain' => "-----BEGIN CERTIFICATE-----\nca\n-----END CERTIFICATE-----"])
            ->assertOk()
            ->assertJsonPath('ssl.custom', true)
            ->assertJsonPath('ssl.names', ['shop.example.com'])
            ->assertJsonMissingPath('ssl.private_key_path');

        // The leaf comes first and the CA's certificate follows it.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/ssl/install')
            && $request['domain'] === 'shop.example.com'
            && $request['certificate'] === self::CERTIFICATE."\n-----BEGIN CERTIFICATE-----\nca\n-----END CERTIFICATE-----\n"
            && $request['private_key'] === self::KEY."\n"
            && $request->hasHeader('Authorization', 'Bearer secret'));

        $site->refresh();
        $this->assertTrue((bool) $site->enable_ssl);
        $this->assertSame('custom', $site->ssl_mode);
        $certificate = SslCertificate::query()->where('domain', 'shop.example.com')->sole();
        $this->assertSame('custom', $certificate->type);
        $this->assertFalse($certificate->auto_renew);
        $this->assertSame('2027-03-01', $certificate->expires_at->toDateString());
        $this->assertNull($certificate->getRawOriginal('private_key_pem'));
        $this->assertNull($certificate->getRawOriginal('certificate_pem'));
        $this->assertDatabaseHas('managed_domains', ['name' => 'shop.example.com', 'ssl_status' => 'valid', 'ssl_enabled' => true]);
    }

    public function test_what_drust_refuses_is_shown_and_changes_nothing(): void
    {
        Http::fake(['drust.test/api/v1/ssl/install' => Http::response(['success' => false, 'message' => 'Failed: The private key does not belong to this certificate.'])]);
        $site = $this->website($admin = $this->user());

        $this->as($admin);
        $this->upload($site, ['certificate' => self::CERTIFICATE, 'private_key' => self::KEY])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The private key does not belong to this certificate.');

        $this->assertFalse((bool) $site->fresh()->enable_ssl);
        $this->assertSame('none', $site->fresh()->ssl_mode);
        $this->assertSame(0, SslCertificate::query()->count());
    }

    public function test_both_texts_are_needed_and_a_local_name_never_reaches_drust(): void
    {
        Http::fake();
        $admin = $this->user();
        $this->as($admin);

        $this->upload($this->website($admin), ['certificate' => self::CERTIFICATE])->assertStatus(422)->assertJsonValidationErrors('private_key');
        $this->upload($this->website($admin, 'shop.test'), ['certificate' => self::CERTIFICATE, 'private_key' => self::KEY])->assertStatus(422);
        $this->upload($this->website($admin, '203.0.113.9'), ['certificate' => self::CERTIFICATE, 'private_key' => self::KEY])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_another_customers_website_is_not_found(): void
    {
        Http::fake();
        $site = $this->website($this->user(''));

        $this->as($this->user(''));
        $this->upload($site, ['certificate' => self::CERTIFICATE, 'private_key' => self::KEY])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_daily_check_keeps_an_uploaded_certificate_as_it_is(): void
    {
        Http::fake(['drust.test/api/v1/ssl/ensure' => Http::response($this->custom('2027-03-01T00:00:00+00:00'))]);
        $site = $this->website($this->user(), extra: ['enable_ssl' => true, 'ssl_mode' => 'custom']);
        SslCertificate::query()->forceCreate(['id' => (string) Str::uuid(), 'domain' => $site->domain, 'type' => 'custom', 'auto_renew' => false, 'expiry_alert_days' => 30]);

        app(SslLifecycleService::class)->ensureForWebsite($site);

        $certificate = SslCertificate::query()->sole();
        $this->assertSame('custom', $certificate->type);
        $this->assertFalse($certificate->auto_renew);
        $this->assertSame(30, $certificate->expiry_alert_days);
        $this->assertSame('custom', $site->fresh()->ssl_mode);
    }

    public function test_a_reinstall_goes_back_to_an_issued_certificate(): void
    {
        Http::fake(['drust.test/api/v1/ssl/ensure' => Http::response(['success' => true, 'data' => [
            'status' => 'valid', 'issued' => true, 'renewed' => false, 'expires_at' => '2027-02-01T00:00:00+00:00',
            'certificate_path' => '/etc/letsencrypt/live/shop.example.com/fullchain.pem',
        ]])]);
        $site = $this->website($this->user(), extra: ['enable_ssl' => true, 'ssl_mode' => 'custom']);
        SslCertificate::query()->forceCreate(['id' => (string) Str::uuid(), 'domain' => $site->domain, 'type' => 'custom', 'auto_renew' => false, 'expiry_alert_days' => 7]);

        app(SslLifecycleService::class)->ensureForWebsite($site, force: true);

        $certificate = SslCertificate::query()->sole();
        $this->assertSame('letsencrypt', $certificate->type);
        $this->assertTrue($certificate->auto_renew);
        $this->assertNull($certificate->expiry_alert_days);
        $this->assertSame('letsencrypt', $site->fresh()->ssl_mode);
    }

    public function test_the_owner_is_warned_once_at_30_7_and_1_days_and_when_it_has_ended(): void
    {
        $reseller = $this->user('reseller');
        $owner = User::factory()->create(['reseller_id' => $reseller->id]);
        $site = $this->website($owner, extra: ['enable_ssl' => true, 'ssl_mode' => 'custom', 'assigned_reseller_id' => $reseller->id]);
        $now = Carbon::parse('2026-11-11 12:00:00');
        $certificate = SslCertificate::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => $site->domain, 'type' => 'custom', 'auto_renew' => false,
            'expires_at' => $now->copy()->addDays(40),
        ]);
        // An issued certificate renews itself and is never warned about.
        SslCertificate::query()->forceCreate(['id' => (string) Str::uuid(), 'domain' => 'other.example.com', 'type' => 'letsencrypt', 'expires_at' => $now->copy()->addDays(2)]);
        $alert = app(CustomCertificateExpiryAlert::class);
        $at = fn (float $daysLeft) => $alert->run($certificate->expires_at->copy()->subSeconds((int) ($daysLeft * 86400)));

        $this->assertSame(0, $at(40));
        $this->assertSame(0, $at(30.5));
        $this->assertSame(1, $at(30));
        $this->assertSame(0, $at(29));
        $this->assertSame(0, $at(7.01));
        $this->assertSame(1, $at(6));
        $this->assertSame(1, $at(0.5));
        $this->assertSame(0, $at(0.2));
        $this->assertSame(1, $at(0));
        $this->assertSame(0, $at(-3));

        $sent = Notification::query()->where('type', 'ssl_expiry')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(4, $sent);
        $this->assertEqualsCanonicalizing(
            ['SSL certificate for shop.example.com expires in 30 days', 'SSL certificate for shop.example.com expires in 6 days', 'SSL certificate for shop.example.com expires in 1 day', 'SSL certificate for shop.example.com has expired'],
            $sent->pluck('title')->all(),
        );
        $this->assertSame(['failed' => 1, 'warning' => 3], $sent->countBy('status')->sortKeys()->all());
        $this->assertTrue($sent->every(fn ($n) => $n->assigned_user_id === $owner->id && $n->assigned_reseller_id === $reseller->id));
    }

    public function test_a_new_upload_starts_the_warnings_over_and_a_site_without_ssl_gets_none(): void
    {
        Http::fake(['drust.test/api/v1/ssl/install' => Http::response($this->custom('2026-11-30T00:00:00+00:00', ['issued' => true]))]);
        $site = $this->website($admin = $this->user(), extra: ['enable_ssl' => true, 'ssl_mode' => 'custom']);
        SslCertificate::query()->forceCreate(['id' => (string) Str::uuid(), 'domain' => $site->domain, 'type' => 'custom', 'auto_renew' => false, 'expiry_alert_days' => 0, 'expires_at' => '2026-11-01']);
        $alert = app(CustomCertificateExpiryAlert::class);
        $now = Carbon::parse('2026-11-11 12:00:00');

        $this->as($admin);
        $this->upload($site, ['certificate' => self::CERTIFICATE, 'private_key' => self::KEY])->assertOk();
        $this->assertNull(SslCertificate::query()->sole()->expiry_alert_days);
        $this->assertSame(1, $alert->run($now));
        $this->assertSame(30, SslCertificate::query()->sole()->expiry_alert_days);

        // SSL switched off: the certificate serves nobody, so nobody is warned.
        $site->forceFill(['enable_ssl' => false])->save();
        $this->assertSame(0, $alert->run($now->copy()->addDays(15)));
    }
}
