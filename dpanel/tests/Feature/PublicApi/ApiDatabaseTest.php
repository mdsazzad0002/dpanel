<?php

namespace Tests\Feature\PublicApi;

use App\Models\DatabaseRequest;
use App\Models\PackagePlan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Backlog U1: databases through /api/v1, only for the owner's websites. */
class ApiDatabaseTest extends PublicApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['dpanel.database_api_url' => 'http://drust.test/api/v1/database-request']);
        Http::fake(['drust.test/*' => Http::response(['success' => true, 'message' => 'ok'])]);
    }

    public function test_a_database_is_made_for_the_users_website_with_its_prefix(): void
    {
        $customer = $this->user();
        $this->website($customer, 'shop.test');
        $plain = $this->token($customer, ['databases:write']);

        $response = $this->api($plain, 'POST', '/databases', ['website' => 'shop.test', 'name' => 'app', 'user' => 'app'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'shop_test_app')
            ->assertJsonPath('data.user', 'shop_test_app')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.owner_id', $customer->id);

        $this->assertNotEmpty($response->json('data.password'));
        Http::assertSent(fn ($request) => $request['database_name'] === 'shop_test_app' && $request['action'] === 'create');

        // The password is only shown when it is made.
        $this->api($plain, 'GET', '/databases')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_a_database_cannot_be_made_for_another_users_website(): void
    {
        $customer = $this->user();
        $this->website($this->user(), 'theirs.test');

        $this->api($this->token($customer, ['databases:write']), 'POST', '/databases', ['website' => 'theirs.test', 'name' => 'app', 'user' => 'app'])
            ->assertUnprocessable()->assertJsonStructure(['message']);
        $this->assertSame(0, DatabaseRequest::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_package_database_limit_applies(): void
    {
        $plan = PackagePlan::query()->create(['id' => (string) Str::uuid(), 'name' => 'Tiny', 'slug' => 'tiny', 'max_databases' => 0]);
        $customer = $this->user('general', ['package_id' => $plan->id]);
        $this->website($customer, 'shop.test');

        $this->api($this->token($customer, ['databases:write']), 'POST', '/databases', ['website' => 'shop.test', 'name' => 'app', 'user' => 'app'])
            ->assertUnprocessable()->assertJsonStructure(['message', 'errors']);
        $this->assertSame(0, DatabaseRequest::query()->count());
    }

    public function test_only_the_owners_databases_are_listed_and_deleted(): void
    {
        $customer = $this->user();
        $this->website($customer, 'shop.test');
        $this->website($this->user(), 'theirs.test');
        $mine = $this->databaseFor('shop.test', $customer->id);
        $theirs = $this->databaseFor('theirs.test', null);
        $plain = $this->token($customer, ['databases:write']);

        $this->api($plain, 'GET', '/databases')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->api($plain, 'DELETE', "/databases/{$theirs->id}")->assertNotFound();
        $this->api($plain, 'DELETE', "/databases/{$mine->id}")->assertOk();
        $this->assertSame([$theirs->id], DatabaseRequest::query()->pluck('id')->all());
    }

    private function databaseFor(string $domain, ?int $owner): DatabaseRequest
    {
        return DatabaseRequest::query()->create([
            'id' => (string) Str::uuid(), 'engine' => 'mariadb', 'domain' => $domain,
            'database_name' => str_replace('.', '_', $domain).'_db', 'database_user' => 'u', 'database_password' => 'secret',
            'database_host' => '127.0.0.1', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'status' => 'active',
            'assigned_user_id' => $owner,
        ]);
    }
}
