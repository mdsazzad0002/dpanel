<?php

namespace Tests\Feature;

use App\Jobs\QuickExportJob;
use App\Models\DatabaseRequest;
use App\Models\User;
use App\Models\Website;
use App\Services\Backup\QuickExportJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteBulkExportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('e', 64);
        URL::defaults(['token' => $this->token]);
        config(['serverpanel.execution_api_base_url' => 'http://127.0.0.1:1', 'serverpanel.execution_api_token' => 'secret']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function website(string $domain): Website
    {
        return Website::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'domain' => $domain,
            'root_path' => '/home/site/public_html',
            'site_owner' => 'site',
            'php_version' => '8.3',
            'status' => 'live',
        ]);
    }

    private function bulkExport(array $payload)
    {
        return $this->withoutMiddleware()->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token])
            ->postJson("/cpsess{$this->token}/websites/bulk-export", $payload);
    }

    public function test_it_queues_files_and_every_active_database_per_website(): void
    {
        Queue::fake();
        $shop = $this->website('shop.test');
        $blog = $this->website('blog.test');
        foreach (['shop_db' => 'active', 'shop_old' => 'deleted'] as $name => $status) {
            DatabaseRequest::query()->forceCreate([
                'id' => (string) Str::uuid(), 'engine' => 'mariadb', 'domain' => 'shop.test',
                'database_name' => $name, 'database_user' => 'u', 'database_password' => 'p',
                'database_host' => '127.0.0.1', 'status' => $status,
            ]);
        }

        $response = $this->bulkExport(['website_ids' => [$shop->id, $blog->id], 'files' => true, 'databases' => true]);

        $response->assertOk()->assertJson(['ok' => true])->assertJsonCount(3, 'items');
        Queue::assertPushed(QuickExportJob::class, 3);
        Queue::assertPushed(QuickExportJob::class, fn ($job) => $job->content === 'database' && $job->websiteId === (string) $shop->id);
        $this->assertSame('queued', QuickExportJobStatus::get($response->json('items.0.export_id'))['stage'] ?? null);
    }

    public function test_it_rejects_an_empty_selection_of_content(): void
    {
        Queue::fake();
        $site = $this->website('shop.test');

        $this->bulkExport(['website_ids' => [$site->id], 'files' => false, 'databases' => false])->assertStatus(422);
        Queue::assertNothingPushed();
    }
}
