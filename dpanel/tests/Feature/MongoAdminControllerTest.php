<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MongoAdminClient;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class MongoAdminControllerTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('d', 64);
    }

    public function test_run_forwards_an_allowed_operation(): void
    {
        $this->mock(MongoAdminClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('run')->once()
                ->withArgs(fn ($op) => json_encode($op) === '{"action":"find","db":"shop","collection":"orders","filter":{},"sort":{"name":1}}')
                ->andReturn(['success' => true, 'message' => 'OK', 'data' => ['documents' => [], 'total' => 0]]);
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/mongodb/admin/run", ['op' => [
                'action' => 'find', 'db' => 'shop', 'collection' => 'orders', 'filter' => new \stdClass, 'sort' => ['name' => 1],
            ]])
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['total' => 0]]);
    }

    public function test_run_reports_failures(): void
    {
        $this->mock(MongoAdminClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('run')->andReturn(['success' => false, 'message' => 'MongoDB is not reachable.', 'data' => null]);
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/mongodb/admin/run", ['op' => ['action' => 'overview']])
            ->assertStatus(422)
            ->assertJson(['message' => 'MongoDB is not reachable.']);
    }

    public function test_run_rejects_unknown_actions(): void
    {
        $this->mock(MongoAdminClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });

        foreach (['shutdown', 'export'] as $action) {
            $this->request()
                ->postJson("/cpsess{$this->token}/databases/mongodb/admin/run", ['op' => ['action' => $action]])
                ->assertStatus(422);
        }
    }

    public function test_only_admins_open_every_database(): void
    {
        $this->mock(MongoAdminClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });

        $this
            ->withoutMiddleware()
            ->actingAs(User::factory()->make(['role' => 'user']))
            ->withSession(['panel_session_token' => $this->token])
            ->postJson("/cpsess{$this->token}/databases/mongodb/admin/run", ['op' => ['action' => 'overview']])
            ->assertForbidden();
    }

    public function test_the_page_can_not_provision_users(): void
    {
        $this->mock(MongoAdminClient::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/mongodb/admin/run", ['op' => ['action' => 'provision_user', 'db' => 'x', 'user' => 'root', 'password' => 'p']])
            ->assertStatus(422);
    }

    public function test_export_downloads_documents_as_json(): void
    {
        $this->mock(MongoAdminClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('run')->once()
                ->withArgs(fn ($op) => json_encode($op) === '{"action":"export","db":"shop","collection":"orders","filter":{"paid":true}}')
                ->andReturn(['success' => true, 'message' => 'OK', 'data' => ['documents' => [['_id' => ['$oid' => 'abc'], 'paid' => true]]]]);
        });

        $response = $this->request()
            ->get("/cpsess{$this->token}/databases/mongodb/admin/export?db=shop&collection=orders&filter=".urlencode('{"paid":true}'));

        $response->assertOk()->assertDownload('shop.orders.json');
        $this->assertSame([['_id' => ['$oid' => 'abc'], 'paid' => true]], json_decode($response->streamedContent(), true));
    }

    public function test_client_sends_the_saved_login_to_drust(): void
    {
        config([
            'serverpanel.execution_api_base_url' => 'http://drust.test',
            'serverpanel.execution_api_token' => 'secret',
            'mongodb.admin_username' => 'admin',
            'mongodb.admin_password' => 'pw',
        ]);
        Http::fake([
            'drust.test/api/v1/mongodb/admin' => Http::response(['success' => true, 'message' => 'OK', 'data' => ['databases' => []]]),
        ]);

        $result = (new MongoAdminClient)->run(['action' => 'overview']);

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret')
            && $request['connection']['username'] === 'admin'
            && $request['connection']['password'] === 'pw'
            && $request['op'] === ['action' => 'overview']);
    }

    public function test_client_refuses_without_a_saved_password(): void
    {
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'mongodb.admin_password' => '']);
        Http::fake();

        $this->assertFalse((new MongoAdminClient)->run(['action' => 'overview'])['success']);
        Http::assertNothingSent();
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs(User::factory()->make(['role' => 'admin']))
            ->withSession(['panel_session_token' => $this->token]);
    }
}
