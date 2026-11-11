<?php

namespace Tests\Feature\Extensions;

use App\Models\Extension;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Backlog U7: panel changes reach subscribed extensions as signed webhooks. */
class ExtensionWebhookTest extends ExtensionTestCase
{
    private const HOOK = 'https://uptime.example.net/dpanel/events';

    /** The receiver's side of the check, as docs/extensions.md describes it. */
    private function verifies(Request $request, string $secret): bool
    {
        $timestamp = (int) $request->header('X-Dpanel-Timestamp')[0];
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret);

        return hash_equals($expected, $request->header('X-Dpanel-Signature')[0]) && abs(time() - $timestamp) < 300;
    }

    public function test_a_new_website_is_sent_signed_to_a_subscribed_extension(): void
    {
        Http::fake([self::HOOK => Http::response('', 204)]);
        $secret = $this->install($this->admin(), $this->manifest())->json('credentials.secret');

        $site = $this->website('shop.example.com');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($secret, $site) {
            $body = json_decode($request->body(), true);

            return $request->url() === self::HOOK
                && $request->method() === 'POST'
                && $request->header('X-Dpanel-Event')[0] === 'website.created'
                && $request->header('X-Dpanel-Delivery')[0] === $body['id']
                && $body['extension'] === 'acme.uptime'
                && $body['data'] === ['id' => $site->id, 'domain' => 'shop.example.com', 'type' => 'primary', 'parent_id' => null, 'owner_id' => null]
                && $this->verifies($request, $secret)
                && ! $this->verifies($request, 'some-other-secret');
        });
        $extension = Extension::query()->sole();
        $this->assertSame(204, $extension->last_delivery_status);
        $this->assertNull($extension->last_delivery_error);

        $site->delete();
        Http::assertSent(fn (Request $request) => $request->header('X-Dpanel-Event')[0] === 'website.deleted');
    }

    public function test_nothing_is_sent_without_subscription_scope_or_while_disabled(): void
    {
        Http::fake();
        $admin = $this->admin();
        // Subscribed, but websites:read was not approved, so the event is dropped.
        $this->install($admin, $this->manifest(), ['dns:read'])->assertCreated();
        // Approved, but not subscribed.
        $this->install($admin, $this->manifest(['id' => 'acme.quiet', 'events' => []]))->assertCreated();
        // Subscribed and approved, but disabled.
        $this->install($admin, $this->manifest(['id' => 'acme.off']))->assertCreated();
        $off = Extension::query()->where('identifier', 'acme.off')->sole();
        $this->actingAsPanelUser($admin)->putJson(route('system.extensions.state', $off), ['enabled' => false])->assertOk();

        $this->website('shop.example.com');

        Http::assertNothingSent();
    }

    public function test_a_failed_delivery_is_recorded_and_never_breaks_the_change(): void
    {
        Http::fake([self::HOOK => Http::response('nope', 500)]);
        $this->install($this->admin(), $this->manifest())->assertCreated();

        $site = $this->website('shop.example.com');

        $this->assertNotNull($site->fresh());
        $extension = Extension::query()->sole();
        $this->assertSame(500, $extension->last_delivery_status);
        $this->assertSame('HTTP 500', $extension->last_delivery_error);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake([
            self::HOOK => Http::response('', 302, ['Location' => 'http://127.0.0.1:7777/internal']),
            '127.0.0.1:7777/*' => Http::response('', 200),
        ]);
        $this->install($this->admin(), $this->manifest())->assertCreated();

        $this->website('shop.example.com');

        Http::assertSentCount(1);
        $this->assertSame('HTTP 302', Extension::query()->sole()->last_delivery_error);
    }

    public function test_the_admin_can_send_a_ping(): void
    {
        Http::fake([self::HOOK => Http::response('', 200)]);
        $admin = $this->admin();
        $secret = $this->install($admin, $this->manifest())->json('credentials.secret');
        $extension = Extension::query()->sole();

        $this->actingAsPanelUser($admin)->postJson(route('system.extensions.ping', $extension))
            ->assertOk()->assertJsonPath('ok', true);
        Http::assertSent(fn (Request $request) => $request->header('X-Dpanel-Event')[0] === 'ping' && $this->verifies($request, $secret));

        $this->actingAsPanelUser($admin)->putJson(route('system.extensions.state', $extension), ['enabled' => false])->assertOk();
        $this->actingAsPanelUser($admin)->postJson(route('system.extensions.ping', $extension))->assertUnprocessable();
    }
}
