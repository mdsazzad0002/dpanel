<?php

namespace Tests\Feature\Extensions;

use App\Http\Controllers\Settings\ExtensionController;
use App\Models\Extension;

/** Backlog U7: an extension's admin page is a sandboxed iframe from its own origin. */
class ExtensionFrameTest extends ExtensionTestCase
{
    public function test_the_page_frames_the_extension_sandboxed_with_a_csp(): void
    {
        $admin = $this->admin();
        $this->install($admin, $this->manifest())->assertCreated();
        $extension = Extension::query()->sole();

        $response = $this->actingAsPanelUser($admin)->get(route('system.extensions.page', $extension))->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('System/Extensions/Page')
            ->where('frame.origin', 'https://uptime.example.net')
            ->where('frame.sandbox', 'allow-scripts allow-forms allow-popups')
            ->where('frame.referrerpolicy', 'no-referrer')
            ->where('frame.src', fn (string $src) => str_starts_with($src, 'https://uptime.example.net/dpanel?dpanel_context=')));
        $sandbox = explode(' ', ExtensionController::FRAME_SANDBOX);
        $this->assertNotContains('allow-same-origin', $sandbox);
        $this->assertNotContains('allow-top-navigation', $sandbox);
        $this->assertSame("frame-src https://uptime.example.net; frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_context_is_signed_short_lived_and_names_the_viewer(): void
    {
        $admin = $this->admin();
        $secret = $this->install($admin, $this->manifest())->json('credentials.secret');
        $extension = Extension::query()->sole();

        $src = '';
        $this->actingAsPanelUser($admin)->get(route('system.extensions.page', $extension))
            ->assertInertia(function ($page) use (&$src) {
                $src = $page->toArray()['props']['frame']['src'];
            });
        parse_str((string) parse_url($src, PHP_URL_QUERY), $query);
        [$payload, $signature] = explode('.', $query['dpanel_context']);

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
        $this->assertTrue(hash_equals($expected, $signature));
        $context = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame('acme.uptime', $context['extension']);
        $this->assertSame($admin->id, $context['user']['id']);
        $this->assertSame(300, $context['exp'] - $context['iat']);
        $this->assertArrayNotHasKey('email', $context['user']);
    }

    public function test_an_extension_without_a_page_has_none(): void
    {
        $admin = $this->admin();
        $manifest = $this->manifest();
        unset($manifest['ui']);
        $this->install($admin, $manifest)->assertCreated();

        $this->actingAsPanelUser($admin)->get(route('system.extensions.page', Extension::query()->sole()))->assertNotFound();
    }

    public function test_others_cannot_open_the_page(): void
    {
        $this->install($this->admin(), $this->manifest())->assertCreated();
        $reseller = \App\Models\User::factory()->create(['role' => 'reseller']);

        $this->actingAsPanelUser($reseller)->get(route('system.extensions.page', Extension::query()->sole()))->assertForbidden();
    }
}
