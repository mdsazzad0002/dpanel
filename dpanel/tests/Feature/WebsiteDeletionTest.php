<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use App\Services\Website\WebsiteDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting a main website takes its aliases with it, and its system user when
 * nothing else is left on it; with a subdomain left only public_html goes.
 */
class WebsiteDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['dpanel.execution_api_base_url' => 'http://drust.test', 'dpanel.execution_api_token' => 'secret']);
        Http::fake(['drust.test/*' => Http::response(['success' => true])]);
    }

    private function site(string $domain, string $owner, string $type = 'primary', ?Website $parent = null, string $dir = 'public_html'): Website
    {
        return Website::query()->create([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'hostname' => $domain,
            'project_root' => "/home/{$owner}", 'root_path' => "/home/{$owner}/{$dir}", 'site_owner' => $owner,
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => $type,
            'ssl_mode' => 'none', 'parent_id' => $parent?->id, 'assigned_user_id' => User::factory()->create()->id,
        ]);
    }

    /** @return array{delete_user: bool, paths: list<string>}|null */
    private function drustDelete(): ?array
    {
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/v1/website/delete'))->first();

        return $sent ? ['delete_user' => (bool) $sent[0]['delete_user'], 'paths' => $sent[0]['paths']] : null;
    }

    public function test_a_main_website_alone_goes_with_its_aliases_and_system_user(): void
    {
        $main = $this->site('shop.test', 'shop_test');
        $www = $this->site('www.shop.test', 'shop_test', 'alias', $main);
        $other = $this->site('other.test', 'other_test');

        $messages = app(WebsiteDeletionService::class)->delete($main, null);

        $this->assertTrue($this->drustDelete()['delete_user']);
        $this->assertNull($main->fresh());
        $this->assertNull($www->fresh());
        $this->assertNotNull($other->fresh());
        $this->assertContains('Also removed: www.shop.test.', $messages);
    }

    public function test_trash_keeps_an_archive_and_permanent_keeps_nothing(): void
    {
        $kept = $this->site('kept.test', 'kept_test');
        $gone = $this->site('gone.test', 'gone_test');
        $archives = fn () => Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/v1/website/archive'))->count();

        app(WebsiteDeletionService::class)->delete($kept, null);
        $this->assertSame(1, $archives());
        $this->assertSame(1, \App\Models\WebsiteTrashBackup::query()->where('domain', 'kept.test')->count());

        $messages = app(WebsiteDeletionService::class)->delete($gone, null, toTrash: false);
        $this->assertSame(1, $archives(), 'no archive for a permanent delete');
        $this->assertSame(0, \App\Models\WebsiteTrashBackup::query()->where('domain', 'gone.test')->count());
        $this->assertNull($gone->fresh());
        $this->assertContains('Deleted permanently, without a trash copy.', $messages);
    }

    private function database(string $domain, string $name, string $user, string $engine = 'mariadb'): \App\Models\DatabaseRequest
    {
        return \App\Models\DatabaseRequest::query()->create([
            'id' => (string) Str::uuid(), 'engine' => $engine, 'domain' => $domain, 'database_name' => $name,
            'database_user' => $user, 'database_password' => 'secret-pw', 'database_host' => '127.0.0.1',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'status' => 'active',
        ]);
    }

    /** @return list<array{action: string, engine: string, database_name: string}> */
    private function databaseRequests(): array
    {
        return Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/v1/database-request'))
            ->map(fn ($pair) => ['action' => $pair[0]['action'], 'engine' => $pair[0]['engine'], 'database_name' => $pair[0]['database_name']])->values()->all();
    }

    public function test_databases_stay_on_the_server_unless_the_box_is_ticked(): void
    {
        config(['dpanel.database_api_url' => 'http://drust.test/api/v1/database-request']);
        $site = $this->site('shop.test', 'shop_test');
        $this->database('shop.test', 'shop_db', 'shop_user');

        $messages = app(WebsiteDeletionService::class)->delete($site, null);

        $this->assertSame([], $this->databaseRequests());
        $this->assertContains('Databases kept on the server: shop_db.', $messages);
    }

    public function test_with_trash_only_mariadb_databases_are_dropped_and_they_are_in_the_archive(): void
    {
        config(['dpanel.database_api_url' => 'http://drust.test/api/v1/database-request']);
        $site = $this->site('shop.test', 'shop_test');
        $this->database('shop.test', 'shop_db', 'shop_user');
        $this->database('shop.test', 'shop_pg', 'shop_pg_user', 'postgresql');

        $messages = app(WebsiteDeletionService::class)->delete($site, null, toTrash: true, dropDatabases: true);

        $this->assertSame([['action' => 'drop', 'engine' => 'mariadb', 'database_name' => 'shop_db']], $this->databaseRequests());
        $this->assertContains('shop_pg kept on the server: the trash archive holds only MariaDB databases.', $messages);
        $archived = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/v1/website/archive'))->first()[0]['website']['database_requests'];
        $this->assertSame(['shop_db'], array_column($archived, 'name'));
    }

    public function test_permanent_drops_every_database_and_keeps_a_user_another_database_uses(): void
    {
        config(['dpanel.database_api_url' => 'http://drust.test/api/v1/database-request']);
        $site = $this->site('shop.test', 'shop_test');
        $this->database('shop.test', 'shop_db', 'shared_user');
        $this->database('shop.test', 'shop_pg', 'shop_pg_user', 'postgresql');
        $this->database('other.test', 'other_db', 'shared_user');

        app(WebsiteDeletionService::class)->delete($site, null, toTrash: false, dropDatabases: true);

        $this->assertSame([
            ['action' => 'drop_database', 'engine' => 'mariadb', 'database_name' => 'shop_db'],
            ['action' => 'drop', 'engine' => 'postgresql', 'database_name' => 'shop_pg'],
        ], $this->databaseRequests());
    }

    public function test_restoring_from_trash_makes_a_dropped_mariadb_database_again(): void
    {
        config(['dpanel.database_api_url' => 'http://drust.test/api/v1/database-request']);

        app(\App\Services\Database\DatabaseServerRemover::class)->recreate([
            ['name' => 'shop_db', 'user' => 'shop_user', 'password' => 'secret-pw', 'host' => '127.0.0.1', 'engine' => 'mariadb'],
            ['name' => 'old_db', 'user' => 'old_user', 'password' => 'pw'],
            ['name' => 'pg_db', 'user' => 'pg_user', 'password' => 'pw', 'engine' => 'postgresql'],
            ['name' => 'no_pw_db', 'user' => 'x', 'password' => ''],
        ]);

        $this->assertSame([
            ['action' => 'create', 'engine' => 'mariadb', 'database_name' => 'shop_db'],
            ['action' => 'create', 'engine' => 'mariadb', 'database_name' => 'old_db'],
        ], $this->databaseRequests());
    }

    public function test_with_a_subdomain_left_only_public_html_goes_and_the_system_user_stays(): void
    {
        $main = $this->site('shop.test', 'shop_test');
        $www = $this->site('www.shop.test', 'shop_test', 'alias', $main);
        $blog = $this->site('blog.shop.test', 'shop_test', 'subdomain', $main, 'blog');
        $blogWww = $this->site('www.blog.shop.test', 'shop_test', 'alias', $blog, 'blog');

        app(WebsiteDeletionService::class)->delete($main, null);

        $this->assertSame(['delete_user' => false, 'paths' => ['/home/shop_test/public_html']], $this->drustDelete());
        $this->assertNull($www->fresh());
        $this->assertNotNull($blog->fresh());
        $this->assertNotNull($blogWww->fresh());
    }

    public function test_deleting_the_last_subdomain_removes_the_system_user(): void
    {
        $blog = $this->site('blog.shop.test', 'shop_test', 'subdomain', null, 'blog');

        app(WebsiteDeletionService::class)->delete($blog, null);

        $this->assertTrue($this->drustDelete()['delete_user']);
        $this->assertNull($blog->fresh());
    }

    public function test_a_subdomain_beside_its_main_website_removes_only_its_own_directory(): void
    {
        $main = $this->site('shop.test', 'shop_test');
        $blog = $this->site('blog.shop.test', 'shop_test', 'subdomain', $main, 'blog');

        app(WebsiteDeletionService::class)->delete($blog, null);

        $this->assertSame(['delete_user' => false, 'paths' => ['/home/shop_test/blog']], $this->drustDelete());
        $this->assertNotNull($main->fresh());
    }

    public function test_a_directory_holding_another_websites_files_is_kept(): void
    {
        $main = $this->site('shop.test', 'shop_test');
        $this->site('blog.shop.test', 'shop_test', 'subdomain', $main, 'public_html/blog');

        app(WebsiteDeletionService::class)->delete($main, null);

        $this->assertNull($this->drustDelete(), 'public_html holds the subdomain, so nothing is removed');
        $this->assertNull($main->fresh());
    }
}
