<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebsiteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_websites_created_by_all_resellers(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $resellerOne = User::factory()->create(['name' => 'Reseller One']);
        $resellerOne->assignRole('reseller');

        $resellerTwo = User::factory()->create(['name' => 'Reseller Two']);
        $resellerTwo->assignRole('reseller');

        $websiteOne = $this->createWebsite([
            'domain' => 'alpha-example.test',
            'assigned_reseller_id' => $resellerOne->id,
        ]);
        $websiteTwo = $this->createWebsite([
            'domain' => 'beta-example.test',
            'assigned_reseller_id' => $resellerTwo->id,
        ]);

        $this->actingAs($admin)
            ->get(route('websites.list'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Websites/List')
                ->has('websiteRequests', 2)
                ->where('websiteRequests', function ($websites) use ($websiteOne, $websiteTwo): bool {
                    $domains = collect($websites)->pluck('domain')->sort()->values()->all();

                    return $domains === collect([$websiteOne->domain, $websiteTwo->domain])->sort()->values()->all();
                }));
    }

    public function test_reseller_only_sees_owned_websites_and_cannot_open_other_websites(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $reseller = User::factory()->create(['name' => 'Scoped Reseller']);
        $reseller->assignRole('reseller');

        $otherReseller = User::factory()->create(['name' => 'Other Reseller']);
        $otherReseller->assignRole('reseller');

        $ownedWebsite = $this->createWebsite([
            'domain' => 'owned-example.test',
            'assigned_reseller_id' => $reseller->id,
        ]);
        $foreignWebsite = $this->createWebsite([
            'domain' => 'foreign-example.test',
            'assigned_reseller_id' => $otherReseller->id,
        ]);

        $this->actingAs($reseller)
            ->get(route('websites.list'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Websites/List')
                ->has('websiteRequests', 1)
                ->where('websiteRequests.0.id', $ownedWebsite->id)
                ->where('websiteRequests.0.domain', $ownedWebsite->domain)
                ->where('websiteRequests.0.created_by_label', $reseller->name));

        $this->actingAs($reseller)
            ->get(route('websites.manage', $foreignWebsite->id))
            ->assertForbidden();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createWebsite(array $overrides = []): Website
    {
        $domain = $overrides['domain'] ?? Str::lower(Str::random(12)).'.test';
        $siteOwner = $overrides['site_owner'] ?? 'siteowner';

        return Website::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'domain' => $domain,
            'root_path' => '/home/'.$siteOwner.'/public_html',
            'site_owner' => $siteOwner,
            'php_version' => '8.3',
            'app_installer' => 'none',
            'wordpress_version' => 'latest',
            'enable_ssl' => false,
            'assigned_user_id' => null,
            'assigned_reseller_id' => null,
            'command' => null,
            'status' => 'pending',
        ], $overrides));
    }
}
