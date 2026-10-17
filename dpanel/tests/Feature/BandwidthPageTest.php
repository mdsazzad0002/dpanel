<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class BandwidthPageTest extends TestCase
{
    private string $token;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('b', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->unsignedBigInteger('reseller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('domain');
            $table->string('type')->nullable();
            $table->string('parent_id')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
            $table->timestamps();
        });

        $this->dir = sys_get_temp_dir().'/bandwidth-test-'.uniqid();
        mkdir($this->dir);
        config(['serverpanel.bandwidth_directory' => $this->dir]);
        file_put_contents($this->dir.'/2026-10.json', json_encode([
            'a.test' => ['upload_bytes' => 100, 'download_bytes' => 900, 'requests' => 10,
                'days' => ['2026-10-01' => ['upload_bytes' => 100, 'download_bytes' => 900, 'requests' => 10]]],
            'b.test' => ['upload_bytes' => 50, 'download_bytes' => 4950, 'requests' => 3, 'days' => []],
            'alias-of-a.test' => ['upload_bytes' => 0, 'download_bytes' => 2000, 'requests' => 2,
                'days' => ['2026-10-01' => ['upload_bytes' => 0, 'download_bytes' => 2000, 'requests' => 2]]],
            'deleted.test' => ['upload_bytes' => 0, 'download_bytes' => 700, 'requests' => 1, 'days' => []],
        ]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_admin_sees_every_website_with_alias_and_removed_domain_traffic(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $user = User::factory()->create();
        Website::query()->insert([
            ['id' => 'w1', 'domain' => 'a.test', 'type' => 'main', 'parent_id' => null, 'assigned_user_id' => $user->id],
            ['id' => 'w2', 'domain' => 'B.test', 'type' => 'main', 'parent_id' => null, 'assigned_user_id' => null],
            ['id' => 'w3', 'domain' => 'alias-of-a.test', 'type' => 'alias', 'parent_id' => 'w1', 'assigned_user_id' => null],
        ]);

        $this->as($admin)->get("/cpsess{$this->token}/bandwidth?month=2026-10")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Bandwidth/Index')
                ->where('available', true)
                ->where('showOwner', true)
                ->where('rows.0.domain', 'b.test')
                ->where('rows.0.total_bytes', 5000)
                ->where('rows.1.domain', 'a.test')
                ->where('rows.1.aliases', ['alias-of-a.test'])
                ->where('rows.1.requests', 12)
                ->where('rows.1.total_bytes', 3000)
                ->where('rows.1.owner', $user->name)
                ->where('rows.2.domain', 'deleted.test')
                ->where('rows.2.removed', true)
                ->where('totals.websites', 2)
                ->where('totals.requests', 16)
                ->where('totals.total_bytes', 8700)
                ->where('dailyRows.0.total_bytes', 3000));
    }

    public function test_dashboard_bandwidth_matches_bandwidth_page(): void
    {
        $user = User::factory()->create();
        Website::query()->insert([
            ['id' => 'w1', 'domain' => 'a.test', 'type' => 'main', 'parent_id' => null, 'assigned_user_id' => $user->id],
            ['id' => 'w3', 'domain' => 'alias-of-a.test', 'type' => 'alias', 'parent_id' => 'w1', 'assigned_user_id' => null],
        ]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-17 12:00:00', 'UTC'));

        $page = app(\App\Services\Bandwidth\BandwidthUsageService::class)->report($user, '2026-10');
        $dashboard = (new \ReflectionMethod(\App\Http\Controllers\DashboardController::class, 'bandwidthUsage'))
            ->invoke(app(\App\Http\Controllers\DashboardController::class), $user);

        $this->assertSame(3000, $page['totals']['total_bytes']);
        $this->assertSame(round(3000 / 1024 / 1024 / 1024, 6), $dashboard['used_gb']);
        $this->assertSame(12, $dashboard['requests']);
    }

    public function test_general_user_only_sees_assigned_websites(): void
    {
        $user = User::factory()->create();
        Website::query()->insert([
            ['id' => 'w1', 'domain' => 'a.test', 'type' => 'main', 'parent_id' => null, 'assigned_user_id' => $user->id],
            ['id' => 'w2', 'domain' => 'b.test', 'type' => 'main', 'parent_id' => null, 'assigned_user_id' => null],
        ]);

        $this->as($user)->get("/cpsess{$this->token}/bandwidth?month=2026-10")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Bandwidth/Index')
                ->has('rows', 1)
                ->where('rows.0.domain', 'a.test')
                ->where('showOwner', false)
                ->where('totals.total_bytes', 1000));
    }

    public function test_old_usage_url_redirects_to_bandwidth(): void
    {
        $this->as(User::factory()->create())->get("/cpsess{$this->token}/dashboard/usage?month=2026-09")
            ->assertRedirect(route('bandwidth.index', ['month' => '2026-09']));
    }

    private function as(User $user): static
    {
        return $this->withoutMiddleware()->actingAs($user)->withSession(['panel_session_token' => $this->token]);
    }
}
