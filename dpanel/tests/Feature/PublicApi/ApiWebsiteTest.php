<?php

namespace Tests\Feature\PublicApi;

use App\Models\ActivityLog;
use App\Models\Website;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/** Backlog U1: websites through /api/v1, only the owner's. */
class ApiWebsiteTest extends PublicApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.server_base_dir', '/home');
        config()->set('serverpanel.filemanager_api_url', 'http://drust.test/api/v1/filemanager');
        Http::fake(['http://drust.test/*' => Http::response(['success' => true, 'message' => 'ok', 'data' => []])]);
        Bus::fake();
    }

    public function test_each_role_lists_only_the_websites_it_may_see(): void
    {
        $admin = $this->user('admin');
        $reseller = $this->user('reseller');
        $customer = $this->user('general', ['reseller_id' => $reseller->id]);
        $stranger = $this->user();
        $this->website($customer, 'customer.test');
        $this->website($stranger, 'stranger.test');

        $domains = fn (string $plain) => collect($this->api($plain, 'GET', '/websites')->assertOk()->json('data'))->pluck('domain')->all();

        $this->assertSame(['customer.test', 'stranger.test'], $domains($this->token($admin, ['websites:read'])));
        $this->assertSame(['customer.test'], $domains($this->token($reseller, ['websites:read'])));
        $this->assertSame(['customer.test'], $domains($this->token($customer, ['websites:read'])));
        $this->assertSame(['stranger.test'], $domains($this->token($stranger, ['websites:read'])));
    }

    public function test_another_users_website_is_not_found(): void
    {
        $customer = $this->user();
        $mine = $this->website($customer, 'mine.test');
        $theirs = $this->website($this->user(), 'theirs.test');
        $plain = $this->token($customer, ['websites:write']);

        $this->api($plain, 'GET', "/websites/{$mine->id}")->assertOk()
            ->assertJsonPath('data.domain', 'mine.test')
            ->assertJsonPath('data.type', 'main')
            ->assertJsonPath('data.owner_id', $customer->id);
        $this->api($plain, 'GET', "/websites/{$theirs->id}")->assertNotFound()->assertJsonPath('message', 'Website not found.');
        $this->api($plain, 'DELETE', "/websites/{$theirs->id}")->assertNotFound();
        $this->assertTrue(Website::query()->whereKey($theirs->id)->exists());
    }

    public function test_a_customer_creates_a_website_they_own(): void
    {
        $customer = $this->user();
        $other = $this->user();
        $plain = $this->token($customer, ['websites:write']);

        $response = $this->api($plain, 'POST', '/websites', [
            'domain' => 'shop.test', 'php_version' => '8.3', 'owner_id' => $other->id,
        ])->assertCreated()
            ->assertJsonPath('data.domain', 'shop.test')
            ->assertJsonPath('data.system_user', 'shop_test');

        // A customer always owns what they make, whatever owner_id says.
        $this->assertSame($customer->id, $response->json('data.owner_id'));
        $this->assertSame($customer->id, Website::query()->where('domain', 'shop.test')->sole()->assigned_user_id);
        $this->assertSame(1, ActivityLog::query()->where('action', 'api.request')->count());
    }

    public function test_a_subdomain_is_made_under_the_users_own_parent(): void
    {
        $customer = $this->user();
        $parent = $this->website($customer, 'shop.test');
        $theirs = $this->website($this->user(), 'theirs.test');
        $plain = $this->token($customer, ['websites:write']);

        $this->api($plain, 'POST', '/websites', ['domain' => 'blog.theirs.test', 'type' => 'sub', 'parent_id' => $theirs->id, 'php_version' => '8.3'])->assertNotFound();
        $this->api($plain, 'POST', '/websites', ['domain' => 'blog.other.test', 'type' => 'sub', 'parent_id' => $parent->id, 'php_version' => '8.3'])
            ->assertUnprocessable()->assertJsonValidationErrors('domain');

        $this->api($plain, 'POST', '/websites', ['domain' => 'blog.shop.test', 'type' => 'sub', 'parent_id' => $parent->id, 'php_version' => '8.3'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'sub')
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.system_user', 'shop_test');
    }

    public function test_invalid_input_and_duplicates_are_json_422(): void
    {
        $customer = $this->user();
        $this->website($this->user(), 'taken.test');
        $plain = $this->token($customer, ['websites:write']);

        $this->api($plain, 'POST', '/websites', ['domain' => 'shop.test'])->assertUnprocessable()->assertJsonValidationErrors('php_version');
        $this->api($plain, 'POST', '/websites', ['domain' => 'not a domain', 'php_version' => '8.3'])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->api($plain, 'POST', '/websites', ['domain' => 'taken.test', 'php_version' => '8.3'])->assertUnprocessable()->assertJsonPath('message', 'Website Already Exists');
    }

    public function test_a_reseller_cannot_give_a_website_to_someone_elses_customer(): void
    {
        $reseller = $this->user('reseller');
        $stranger = $this->user();
        $plain = $this->token($reseller, ['websites:write']);

        $this->api($plain, 'POST', '/websites', ['domain' => 'shop.test', 'php_version' => '8.3', 'owner_id' => $stranger->id])->assertForbidden();
        $this->assertFalse(Website::query()->where('domain', 'shop.test')->exists());
    }

    public function test_a_read_token_cannot_create_or_delete(): void
    {
        $customer = $this->user();
        $site = $this->website($customer, 'mine.test');
        $plain = $this->token($customer, ['websites:read']);

        $this->api($plain, 'POST', '/websites', ['domain' => 'shop.test', 'php_version' => '8.3'])->assertForbidden();
        $this->api($plain, 'DELETE', "/websites/{$site->id}")->assertForbidden();
    }

    public function test_deleting_a_subdomain_keeps_its_parent(): void
    {
        $customer = $this->user();
        $parent = $this->website($customer, 'shop.test');
        $sub = $this->website($customer, 'blog.shop.test', ['type' => 'subdomain', 'parent_id' => $parent->id, 'site_owner' => 'shop_test']);
        $plain = $this->token($customer, ['websites:write']);

        $this->api($plain, 'DELETE', "/websites/{$sub->id}")->assertOk()->assertJsonStructure(['message']);

        $this->assertFalse(Website::query()->whereKey($sub->id)->exists());
        $this->assertTrue(Website::query()->whereKey($parent->id)->exists());
    }
}
