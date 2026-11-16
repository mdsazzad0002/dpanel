<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PhpWorkersControllerTest extends TestCase
{
    private string $token;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('f', 64);
        URL::defaults(['token' => $this->token]);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 128)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        config([
            'dpanel.execution_api_base_url' => 'http://drust.test',
            'dpanel.execution_api_token' => 'secret',
        ]);
    }

    public function test_page_opens(): void
    {
        $this->withoutVite();
        $this->request()
            ->get("/dpt{$this->token}/php/workers")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Php/PhpWorkers'));
    }

    public function test_usage_is_read_from_drust(): void
    {
        Http::fake(['drust.test/api/v1/php/workers*' => Http::response([
            'success' => true,
            'data' => ['pools' => [['owner' => 'shop', 'active' => 1, 'running' => 2, 'max_children' => 2]]],
        ])]);

        $this->request()
            ->getJson("/dpt{$this->token}/php/workers/data")
            ->assertOk()
            ->assertJsonPath('data.pools.0.owner', 'shop');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer secret')
            && $request['observer_pid'] === getmypid());
    }

    public function test_a_limit_is_sent_to_drust_and_logged(): void
    {
        Http::fake(['drust.test/api/v1/php/workers' => Http::response([
            'success' => true,
            'message' => 'Worker limit for shop set to 6; PHP-FPM reload scheduled',
            'data' => ['owner' => 'shop', 'max_children' => 6, 'custom' => true],
        ])]);

        $this->request()
            ->postJson("/dpt{$this->token}/php/workers", ['owner' => 'shop', 'max_children' => 6])
            ->assertOk()
            ->assertJsonPath('data.max_children', 6);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['owner'] === 'shop'
            && $request['max_children'] === 6);
        $this->assertSame('php.workers.update', ActivityLog::query()->sole()->action);
    }

    public function test_reset_to_automatic_sends_null(): void
    {
        Http::fake(['drust.test/api/v1/php/workers' => Http::response(['success' => true, 'data' => ['max_children' => 3, 'custom' => false]])]);

        $this->request()
            ->postJson("/dpt{$this->token}/php/workers", ['owner' => 'shop', 'max_children' => null])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['max_children'] === null);
    }

    public function test_out_of_range_limits_and_odd_owners_are_rejected(): void
    {
        Http::fake();

        $this->request()->postJson("/dpt{$this->token}/php/workers", ['owner' => 'shop', 'max_children' => 51])->assertStatus(422);
        $this->request()->postJson("/dpt{$this->token}/php/workers", ['owner' => '../etc', 'max_children' => 4])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_drust_errors_are_shown_without_the_failed_prefix(): void
    {
        Http::fake(['drust.test/api/v1/php/workers' => Http::response(['success' => false, 'message' => 'Failed: PHP 8.3 configuration test failed'])]);

        $this->request()
            ->postJson("/dpt{$this->token}/php/workers", ['owner' => 'shop', 'max_children' => 4])
            ->assertStatus(422)
            ->assertJsonPath('message', 'PHP 8.3 configuration test failed');
        $this->assertSame(0, ActivityLog::query()->count());
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->admin)
            ->withSession(['panel_session_token' => $this->token]);
    }
}
