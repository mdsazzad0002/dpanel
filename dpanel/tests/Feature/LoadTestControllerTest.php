<?php

namespace Tests\Feature;

use App\Http\Controllers\Tools\LoadTestController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoadTestControllerTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

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
        Schema::create('websites', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('domain');
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('assigned_reseller_id')->nullable();
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
        DB::table('websites')->insert([['id' => 'w1', 'domain' => 'mine.test']]);
    }

    private function fire(array $input)
    {
        $request = Request::create('/', 'POST', $input);
        $request->setUserResolver(fn () => $this->admin);

        return (new LoadTestController)->run($request);
    }

    public function test_rejects_a_domain_the_account_does_not_own(): void
    {
        Http::fake();
        $response = $this->fire(['url' => 'https://victim.example', 'requests' => 5, 'concurrency' => 2]);

        $this->assertSame(403, $response->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_rejects_non_http_scheme(): void
    {
        Http::fake();
        $response = $this->fire(['url' => 'ftp://mine.test', 'requests' => 2, 'concurrency' => 1]);

        $this->assertSame(422, $response->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_runs_against_an_owned_domain_and_reports_stats(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $response = $this->fire(['url' => 'https://www.mine.test/health', 'requests' => 6, 'concurrency' => 3]);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://www.mine.test/health', $data['target']);
        $this->assertSame(6, $data['sent']);
        $this->assertSame(6, $data['statuses']['200']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'tools.load_test', 'subject_id' => 'www.mine.test']);
    }

    public function test_connection_failures_are_counted_not_fatal(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $response = $this->fire(['url' => 'https://mine.test', 'requests' => 3, 'concurrency' => 1]);
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $data['failures']);
        $this->assertNull($data['latency']);
    }
}
