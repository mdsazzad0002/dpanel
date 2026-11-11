<?php

namespace Tests\Feature\Extensions;

use App\Models\Extension;
use App\Services\Extensions\ExtensionManifest;
use Illuminate\Validation\ValidationException;

/** Backlog U7: the manifest is strict, and its problems are named clearly. */
class ExtensionManifestTest extends ExtensionTestCase
{
    /** @return list<string> */
    private function problems(array|string $manifest, string $panelVersion = '1.4.0'): array
    {
        try {
            ExtensionManifest::parse(is_string($manifest) ? $manifest : $this->encoded($manifest), $panelVersion, ['panel.example.com']);
        } catch (ValidationException $e) {
            return $e->errors()['manifest'];
        }

        return [];
    }

    private function assertProblem(string $needle, array $problems): void
    {
        $this->assertNotEmpty(array_filter($problems, fn (string $problem) => str_contains($problem, $needle)), "No problem mentions \"{$needle}\": ".implode(' | ', $problems));
    }

    public function test_a_good_manifest_is_read(): void
    {
        $manifest = ExtensionManifest::parse($this->encoded($this->manifest()), '1.4.0', ['panel.example.com']);

        $this->assertSame('acme.uptime', $manifest->id);
        $this->assertSame(['websites:read', 'dns:read'], $manifest->scopes);
        $this->assertSame('https://uptime.example.net/dpanel', $manifest->uiUrl);
        $this->assertSame(['website.created', 'website.deleted'], $manifest->events);
    }

    public function test_broken_json_and_non_objects_are_refused(): void
    {
        $this->assertProblem('not valid JSON', $this->problems('{"id": '));
        $this->assertProblem('JSON object', $this->problems('["acme"]'));
        $this->assertProblem('larger than 64 KB', $this->problems(str_repeat(' ', ExtensionManifest::MAX_BYTES + 1)));
    }

    public function test_each_field_is_checked(): void
    {
        $problems = $this->problems($this->manifest([
            'manifest_version' => 2,
            'id' => 'Acme Uptime!',
            'name' => '',
            'version' => 'one',
            'homepage' => 'http://acme.example',
            'scopes' => ['websites:read', 'servers:root'],
            'runtime' => ['command' => 'rm -rf /'],
            'colour' => 'red',
        ]));

        $this->assertProblem('manifest_version: must be 1', $problems);
        $this->assertProblem('id: use 3 to 64 lowercase', $problems);
        $this->assertProblem('name: is required', $problems);
        $this->assertProblem('version: must be a semantic version', $problems);
        $this->assertProblem('homepage: must be an https://', $problems);
        $this->assertProblem('"servers:root" is not an API scope', $problems);
        $this->assertProblem('runtime: running extension code on this server is not supported', $problems);
        $this->assertProblem('colour: unknown key', $problems);
    }

    public function test_the_panel_version_range_must_include_this_panel(): void
    {
        $this->assertSame([], $this->problems($this->manifest(['panel' => '>=1.4 <1.5'])));
        $this->assertSame([], $this->problems($this->manifest(['panel' => '1.4.0'])));
        $this->assertProblem('needs dPanel >=2.0, and this panel is 1.4.0', $this->problems($this->manifest(['panel' => '>=2.0'])));
        $this->assertProblem('needs dPanel <1.4', $this->problems($this->manifest(['panel' => '<1.4'])));
        $this->assertProblem('panel: write a version range', $this->problems($this->manifest(['panel' => '^1.0 || 2'])));
        $this->assertProblem('unknown version', $this->problems($this->manifest(), ''));
    }

    public function test_urls_must_be_https_on_another_host(): void
    {
        $ui = fn (string $url) => $this->problems($this->manifest(['ui' => ['admin_page' => ['title' => 'Uptime', 'url' => $url]]]));

        $this->assertProblem('must use https://', $ui('http://uptime.example.net/'));
        $this->assertProblem("panel's own host", $ui('https://panel.example.com:8443/ext'));
        $this->assertProblem('user name or password', $ui('https://me:pw@uptime.example.net/'));
        $this->assertProblem('must be an https:// address', $ui('javascript:alert(1)'));
        $this->assertSame([], $ui('http://127.0.0.1:9000/ui'));

        $hook = $this->problems($this->manifest(['webhook' => ['url' => 'ftp://uptime.example.net/']]));
        $this->assertProblem('webhook.url: must be an https:// address', $hook);
    }

    public function test_ui_slots_and_events_are_only_the_known_ones(): void
    {
        $problems = $this->problems($this->manifest([
            'ui' => ['admin_page' => ['title' => 'Uptime', 'url' => 'https://uptime.example.net/'], 'website_tab' => ['title' => 'x']],
            'events' => ['website.created', 'user.created'],
        ]));
        $this->assertProblem('ui.website_tab: unknown UI slot', $problems);
        $this->assertProblem('"user.created" is not an event', $problems);
    }

    public function test_an_event_needs_its_scope_and_a_webhook(): void
    {
        $this->assertProblem('"website.created" needs the websites:read scope', $this->problems($this->manifest(['scopes' => ['dns:read']])));
        $this->assertSame([], $this->problems($this->manifest(['scopes' => ['websites:write']])));

        $manifest = $this->manifest();
        unset($manifest['webhook']);
        $this->assertProblem('events need a webhook address', $this->problems($manifest));
    }

    public function test_review_shows_what_would_be_granted_and_errors_as_422(): void
    {
        $admin = $this->admin();

        $this->actingAsPanelUser($admin)->postJson(route('system.extensions.review'), ['manifest' => $this->encoded($this->manifest())])
            ->assertOk()
            ->assertJsonPath('manifest.id', 'acme.uptime')
            ->assertJsonPath('grantable', ['websites:read', 'dns:read'])
            ->assertJsonPath('installed', null);

        $this->actingAsPanelUser($admin)->postJson(route('system.extensions.review'), ['manifest' => '{"manifest_version": 1}'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manifest');
        $this->assertSame(0, Extension::query()->count());
    }
}
