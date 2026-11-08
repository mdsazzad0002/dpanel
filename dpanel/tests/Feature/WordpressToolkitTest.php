<?php

namespace Tests\Feature;

use App\Jobs\CloneWebsiteJob;
use App\Jobs\WordpressStagingFinishJob;
use App\Models\User;
use App\Models\Website;
use App\Models\WordpressStaging;
use App\Services\Backup\CloneShareJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class WordpressToolkitTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function website(string $domain, ?User $owner = null): Website
    {
        $user = Str::before($domain, '.');

        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => "/home/{$user}", 'root_path' => "/home/{$user}/public_html", 'site_owner' => $user,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => ($owner ?? $this->admin)->id,
        ]);
    }

    private function as(?User $user = null): static
    {
        return $this->withoutMiddleware()->actingAs($user ?? $this->admin)->withSession(['panel_session_token' => $this->token]);
    }

    private function url(string $path): string
    {
        return "/cpsess{$this->token}/wordpress{$path}";
    }

    public function test_updating_named_plugins_sends_only_those_and_returns_fresh_info(): void
    {
        Http::fake(['drust.test/api/v1/wordpress' => Http::sequence()
            ->push(['success' => true, 'data' => ['plugins' => ['ok' => true, 'output' => 'Success', 'error' => '']]])
            ->push(['success' => true, 'data' => ['core_version' => ['ok' => true, 'output' => '6.8', 'error' => ''], 'plugins' => ['ok' => true, 'output' => '[]', 'error' => '']]])]);
        $site = $this->website('shop.test');

        $this->as()->postJson($this->url("/sites/{$site->id}/update"), ['kind' => 'plugins', 'names' => ['woocommerce', 'akismet']])
            ->assertOk()->assertJsonPath('version', '6.8');

        Http::assertSent(fn ($request) => $request['action'] === 'update_plugins'
            && $request['names'] === ['woocommerce', 'akismet']
            && $request['site_owner'] === 'shop'
            && $request['root'] === '/home/shop/public_html');
    }

    public function test_a_plugin_name_that_is_not_a_slug_never_reaches_drust(): void
    {
        Http::fake();
        $site = $this->website('shop.test');

        $this->as()->postJson($this->url("/sites/{$site->id}/update"), ['kind' => 'plugins', 'names' => ['--exec=id']])
            ->assertJsonValidationErrors('names.0');
        $this->as()->postJson($this->url("/sites/{$site->id}/fix"), ['fix' => 'eval'])->assertJsonValidationErrors('fix');
        Http::assertNothingSent();
    }

    public function test_someone_elses_site_is_not_found(): void
    {
        Http::fake();
        $site = $this->website('shop.test');
        $other = User::factory()->create();
        $other->assignRole('user');

        $this->as($other)->getJson($this->url("/sites/{$site->id}"))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_security_check_comes_back_as_checks(): void
    {
        Http::fake(['drust.test/api/v1/wordpress' => Http::response(['success' => true, 'data' => [
            'registration' => ['ok' => true, 'output' => '1', 'error' => ''],
        ]])]);
        $site = $this->website('shop.test');

        $this->as()->getJson($this->url("/sites/{$site->id}/security"))
            ->assertOk()->assertJsonPath('checks.0.key', 'registration')->assertJsonPath('checks.0.fix', 'close_registration');
    }

    public function test_copying_to_staging_clones_then_rewrites_the_address(): void
    {
        Bus::fake();
        Http::fake(['drust.test/api/v1/wordpress' => Http::response(['success' => true, 'data' => ['home' => ['ok' => true, 'output' => "https://shop.test\n", 'error' => '']]])]);
        $live = $this->website('shop.test');
        $stage = $this->website('staging.test');

        $this->as()->postJson($this->url("/sites/{$live->id}/staging"), ['staging_website_id' => $stage->id])->assertOk();
        $this->as()->postJson($this->url("/sites/{$live->id}/staging/copy"), ['direction' => 'to_staging'])
            ->assertOk()->assertJsonPath('staging.stage', 'queued');

        Bus::assertChained([
            fn (CloneWebsiteJob $job) => $job->sourceWebsiteId === $live->id && $job->targetWebsiteId === $stage->id,
            fn (WordpressStagingFinishJob $job) => $job->sourceHome === 'https://shop.test',
        ]);
        $this->as()->postJson($this->url("/sites/{$live->id}/staging/copy"), ['direction' => 'to_production'])->assertStatus(409);
    }

    public function test_a_site_already_in_a_pair_cannot_join_another(): void
    {
        $live = $this->website('shop.test');
        $stage = $this->website('staging.test');
        $third = $this->website('third.test');
        WordpressStaging::create(['production_website_id' => $live->id, 'staging_website_id' => $stage->id]);

        $this->as()->postJson($this->url("/sites/{$third->id}/staging"), ['staging_website_id' => $stage->id])->assertStatus(422);
        $this->as()->postJson($this->url("/sites/{$live->id}/staging"), ['staging_website_id' => $live->id])->assertStatus(422);
    }

    public function test_the_finish_job_rewrites_only_after_a_good_copy(): void
    {
        Http::fake(['drust.test/api/v1/wordpress' => Http::response(['success' => true, 'data' => []])]);
        $live = $this->website('shop.test');
        $stage = $this->website('staging.test');
        $link = WordpressStaging::create(['production_website_id' => $live->id, 'staging_website_id' => $stage->id, 'clone_id' => (string) Str::uuid(), 'direction' => 'to_staging', 'stage' => 'queued']);

        CloneShareJobStatus::set($link->clone_id, ['stage' => 'failed', 'message' => 'Disk full']);
        app()->call([new WordpressStagingFinishJob($link->id, 'https://shop.test'), 'handle']);
        $this->assertSame('failed', $link->fresh()->stage);
        $this->assertSame('Disk full', $link->fresh()->message);
        Http::assertNothingSent();

        CloneShareJobStatus::set($link->clone_id, ['stage' => 'ready']);
        app()->call([new WordpressStagingFinishJob($link->id, 'https://shop.test'), 'handle']);
        $this->assertSame('done', $link->fresh()->stage);
        $this->assertNotNull($link->fresh()->copied_at);
        Http::assertSent(fn ($request) => $request['action'] === 'search_replace'
            && $request['from'] === 'https://shop.test' && $request['to'] === 'https://staging.test'
            && $request['site_owner'] === 'staging');
    }
}
