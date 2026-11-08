<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\PackagePlan;
use App\Models\User;
use App\Models\Website;
use App\Services\ResourceQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Task P2: the package storage limit is the account's, shared by its mailboxes. */
class MailboxStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_storage_limit_counts_all_of_a_users_mailboxes(): void
    {
        $plan = PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'Small', 'slug' => 'small', 'max_storage_mb' => 100, 'max_mailboxes' => 10,
        ]);
        $user = User::factory()->create(['package_id' => $plan->id]);
        Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);
        Mailbox::query()->create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'mailbox' => 'a', 'email' => 'a@shop.test',
            'password' => 'x', 'quota_mb' => 80, 'status' => 'active',
        ]);

        $this->expectException(ValidationException::class);
        app(ResourceQuotaService::class)->assertMailboxAllowed($user, 80);
    }

    public function test_editing_a_mailbox_does_not_count_its_own_quota_twice(): void
    {
        $plan = PackagePlan::query()->create([
            'id' => (string) Str::uuid(), 'name' => 'Small', 'slug' => 'small', 'max_storage_mb' => 100, 'max_mailboxes' => 10,
        ]);
        $user = User::factory()->create(['package_id' => $plan->id]);
        Website::create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'hostname' => 'shop.test',
            'project_root' => '/home/shop', 'root_path' => '/home/shop/public_html', 'site_owner' => 'shop',
            'php_version' => '8.3', 'enable_ssl' => false, 'status' => 'live', 'type' => 'primary',
            'ssl_mode' => 'none', 'assigned_user_id' => $user->id,
        ]);
        $mailbox = Mailbox::query()->create([
            'id' => (string) Str::uuid(), 'domain' => 'shop.test', 'mailbox' => 'a', 'email' => 'a@shop.test',
            'password' => 'x', 'quota_mb' => 80, 'status' => 'active',
        ]);

        app(ResourceQuotaService::class)->assertMailboxAllowed($user, 100, exceptMailboxId: $mailbox->id);
        $this->addToAssertionCount(1);
    }
}
