<?php

namespace Tests\Feature;

use App\Jobs\CloneWebsiteJob;
use App\Jobs\WordpressSafeUpdateJob;
use App\Jobs\WordpressStagingFinishJob;
use App\Models\User;
use App\Models\Website;
use App\Models\WordpressStaging;
use App\Services\Wordpress\WordpressHealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Backlog W5: an update reaches production only after it worked on a fresh staging copy. */
class WordpressSafeUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a', 64);
        URL::defaults(['token' => $this->token]);
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function website(string $domain): Website
    {
        return Website::create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => '/home/shop', 'root_path' => "/home/shop/{$domain}", 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => true, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $this->admin->id,
        ]);
    }

    /** @return array{Website, Website, WordpressStaging} */
    private function pair(string $stage = 'testing'): array
    {
        $live = $this->website('shop.test');
        $copy = $this->website('staging.shop.test');
        $link = WordpressStaging::create([
            'production_website_id' => $live->id, 'staging_website_id' => $copy->id, 'user_id' => $this->admin->id,
            'direction' => WordpressStaging::SAFE_UPDATE, 'stage' => $stage, 'clone_id' => (string) Str::uuid(),
        ]);

        return [$live, $copy, $link];
    }

    /**
     * @param  array<string, string>  $pages  url => body
     * @param  list<list<string>>  $logs  PHP log tails, one per request
     */
    private function fakeServer(array $pages, array $logs = [[]], bool $updateOk = true): void
    {
        $logSequence = Http::sequence();
        foreach ($logs as $lines) {
            $logSequence->push(['success' => true, 'exists' => true, 'lines' => $lines]);
        }
        $logSequence->whenEmpty(Http::response(['success' => true, 'exists' => true, 'lines' => end($logs) ?: []]));
        $fakes = [
            'drust.test/api/v1/wordpress' => Http::response(['success' => true, 'data' => ['update' => ['ok' => $updateOk, 'output' => 'Success', 'error' => $updateOk ? '' : 'Error: plugin zip broken']]]),
            'drust.test/sites/logs*' => $logSequence,
        ];
        foreach ($pages as $url => $body) {
            $fakes[$url] = is_int($body) ? Http::response('', $body) : Http::response($body);
        }
        Http::fake($fakes);
    }

    private function updatesSentTo(string $root): int
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/v1/wordpress') && $request['root'] === $root && str_starts_with((string) $request['action'], 'update_'))->count();
    }

    private function runJob(WordpressStaging $link): WordpressStaging
    {
        (new WordpressSafeUpdateJob($link->id, 'plugins', ['woocommerce'], 'https://shop.test'))->handle(app(\App\Services\Wordpress\WpCliClient::class), app(WordpressHealthCheck::class));

        return $link->fresh();
    }

    public function test_the_endpoint_needs_a_pair_then_chains_copy_rewrite_and_test(): void
    {
        Bus::fake();
        Http::fake(['drust.test/api/v1/wordpress' => Http::response(['success' => true, 'data' => ['home' => ['ok' => true, 'output' => "https://shop.test\n", 'error' => '']]])]);
        $live = $this->website('shop.test');
        $copy = $this->website('staging.shop.test');
        $url = "/dpt{$this->token}/wordpress/sites/{$live->id}/safe-update";
        $as = fn () => $this->withoutMiddleware()->actingAs($this->admin)->withSession(['panel_session_token' => $this->token]);

        $as()->postJson($url, ['kind' => 'plugins', 'names' => ['woocommerce']])->assertStatus(422);
        WordpressStaging::create(['production_website_id' => $live->id, 'staging_website_id' => $copy->id, 'user_id' => $this->admin->id]);

        $as()->postJson($url, ['kind' => 'plugins', 'names' => ['woocommerce', 'bad slug!']])->assertJsonValidationErrors('names.1');
        $as()->postJson($url, ['kind' => 'plugins', 'names' => ['woocommerce']])->assertOk()->assertJsonPath('staging.direction', 'safe_update');
        Bus::assertChained([
            fn (CloneWebsiteJob $job) => $job->sourceWebsiteId === $live->id && $job->targetWebsiteId === $copy->id,
            fn (WordpressStagingFinishJob $job) => $job->sourceHome === 'https://shop.test',
            fn (WordpressSafeUpdateJob $job) => $job->kind === 'plugins' && $job->names === ['woocommerce'] && $job->productionHome === 'https://shop.test',
        ]);
        $as()->postJson($url, ['kind' => 'core'])->assertStatus(409);
    }

    public function test_the_rewrite_hands_a_safe_update_on_instead_of_finishing(): void
    {
        [, , $link] = $this->pair('rewriting');
        \App\Services\Backup\CloneShareJobStatus::set($link->clone_id, ['stage' => 'ready']);
        Http::fake(['drust.test/api/v1/wordpress' => Http::response(['success' => true, 'data' => ['replace' => ['ok' => true, 'output' => '', 'error' => '']]])]);

        (new WordpressStagingFinishJob($link->id, 'https://shop.test'))->handle(app(\App\Services\Wordpress\WpCliClient::class));

        $this->assertSame('testing', $link->fresh()->stage);
        Http::assertSent(fn (Request $request) => $request['action'] === 'search_replace' && $request['root'] === '/home/shop/staging.shop.test' && $request['to'] === 'https://staging.shop.test');
    }

    public function test_a_healthy_staging_lets_production_update(): void
    {
        [, , $link] = $this->pair();
        $this->fakeServer(['staging.shop.test/*' => '<html>ok</html>', 'shop.test/*' => '<html>ok</html>']);

        $link = $this->runJob($link);

        $this->assertSame('done', $link->stage, (string) $link->message);
        $this->assertSame(1, $this->updatesSentTo('/home/shop/staging.shop.test'));
        $this->assertSame(1, $this->updatesSentTo('/home/shop/shop.test'));
    }

    public function test_a_critical_error_on_staging_keeps_production_as_it_was(): void
    {
        [, , $link] = $this->pair();
        Http::fake([
            'drust.test/api/v1/wordpress' => Http::response(['success' => true, 'data' => ['update' => ['ok' => true, 'output' => 'Success', 'error' => '']]]),
            'drust.test/sites/logs*' => Http::response(['success' => true, 'exists' => true, 'lines' => []]),
            // Fine before the update, broken after it.
            'staging.shop.test/*' => Http::sequence()->push('ok')->push('ok')->push('<p>There has been a critical error on this website.</p>')->push('ok'),
        ]);

        $link = $this->runJob($link);

        $this->assertSame('failed', $link->stage);
        $this->assertStringContainsString('production was not touched', (string) $link->message);
        $this->assertStringContainsString('critical error', (string) $link->message);
        $this->assertSame(0, $this->updatesSentTo('/home/shop/shop.test'));
    }

    public function test_a_new_fatal_in_another_sites_files_is_not_this_updates(): void
    {
        [, , $link] = $this->pair();
        $old = '[08-Nov-2026 10:00:00] PHP Warning: old';
        $this->fakeServer(['staging.shop.test/*' => 'ok', 'shop.test/*' => 'ok'], [
            [$old], [$old], [$old, '[08-Nov-2026 10:01:00] PHP Fatal error: Uncaught Error in /home/shop/other.test/wp-content/x.php:3'],
        ]);

        $this->assertSame('done', $this->runJob($link)->stage);
    }

    public function test_a_new_fatal_in_stagings_own_files_stops_the_update(): void
    {
        [, , $link] = $this->pair();
        $old = '[08-Nov-2026 10:00:00] PHP Warning: old';
        $this->fakeServer(['staging.shop.test/*' => 'ok', 'shop.test/*' => 'ok'], [
            [$old], [$old], [$old, '[08-Nov-2026 10:02:00] PHP Fatal error: Uncaught Error in /home/shop/staging.shop.test/wp-content/plugins/woocommerce/x.php:9'],
        ]);

        $link = $this->runJob($link);
        $this->assertSame('failed', $link->stage);
        $this->assertStringContainsString('PHP: ', (string) $link->message);
        $this->assertSame(0, $this->updatesSentTo('/home/shop/shop.test'));
    }

    public function test_a_failed_wp_cli_update_stops_before_production(): void
    {
        [, , $link] = $this->pair();
        $this->fakeServer(['staging.shop.test/*' => 'ok', 'shop.test/*' => 'ok'], [[]], updateOk: false);

        $link = $this->runJob($link);
        $this->assertSame('failed', $link->stage);
        $this->assertStringContainsString('plugin zip broken', (string) $link->message);
        $this->assertSame(0, $this->updatesSentTo('/home/shop/shop.test'));
    }

    public function test_a_copy_that_is_broken_before_the_update_changes_nothing(): void
    {
        [, , $link] = $this->pair();
        $this->fakeServer(['staging.shop.test/*' => 500, 'shop.test/*' => 'ok']);

        $link = $this->runJob($link);
        $this->assertSame('failed', $link->stage);
        $this->assertStringContainsString('already broken before the update', (string) $link->message);
        $this->assertSame(0, $this->updatesSentTo('/home/shop/staging.shop.test'));
    }

    public function test_a_job_for_a_pair_that_is_not_testing_does_nothing(): void
    {
        [, , $link] = $this->pair('failed');
        Http::fake();
        $this->assertSame('failed', $this->runJob($link)->stage);
        Http::assertNothingSent();
    }

    public function test_new_lines_survive_rotation(): void
    {
        $this->assertSame(['c'], WordpressHealthCheck::newLines(['a', 'b'], ['a', 'b', 'c']));
        $this->assertSame([], WordpressHealthCheck::newLines(['a', 'b'], ['a', 'b']));
        $this->assertSame(['x', 'y'], WordpressHealthCheck::newLines(['a', 'b'], ['x', 'y']));
        $this->assertSame(['x'], WordpressHealthCheck::newLines([], ['x']));
    }
}
