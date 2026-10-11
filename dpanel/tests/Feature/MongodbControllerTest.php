<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MongodbServiceManager;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class MongodbControllerTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('d', 64);
    }

    public function test_toggle_turns_mongodb_on_through_the_manager(): void
    {
        $this->mock(MongodbServiceManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('isKnownService')->with('mongodb')->andReturnTrue();
            $mock->shouldReceive('setRunning')->once()->with('mongodb', true)->andReturn(['success' => true]);
            $mock->shouldReceive('statuses')->andReturn([]);
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/mongodb/mongodb/toggle", ['running' => true])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_toggle_rejects_unknown_services(): void
    {
        $this->mock(MongodbServiceManager::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('setRunning');
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/mongodb/postgresql/toggle", ['running' => true])
            ->assertNotFound();
    }

    public function test_manager_only_accepts_mongodb(): void
    {
        $manager = new MongodbServiceManager;

        $this->assertTrue($manager->isKnownService('mongodb'));
        $this->assertFalse($manager->isKnownService('mongo-express'));
        $this->assertFalse($manager->isKnownService('postgresql'));
        $this->assertFalse($manager->setRunning('pgadmin', true)['success']);
    }

    public function test_manager_toggles_mongodb_through_drust(): void
    {
        config([
            'serverpanel.execution_api_base_url' => 'http://drust.test',
            'serverpanel.execution_api_token' => 'secret',
        ]);
        Http::fake([
            'drust.test/api/v1/mongodb/service' => Http::response(['success' => true, 'message' => 'ok']),
        ]);

        $this->assertSame(['success' => true], (new MongodbServiceManager)->setRunning('mongodb', false));

        Http::assertSent(fn ($request) => $request->url() === 'http://drust.test/api/v1/mongodb/service'
            && $request['service'] === 'mongodb'
            && $request['enabled'] === false);
    }

    public function test_mongo_express_is_gone(): void
    {
        $this->mock(MongodbServiceManager::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('setRunning');
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/mongodb/mongo-express/toggle", ['running' => true])
            ->assertNotFound();
        $this->request()
            ->get("/cpsess{$this->token}/databases/mongodb/mongo-express")
            ->assertNotFound();
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs(User::factory()->make())
            ->withSession(['panel_session_token' => $this->token]);
    }
}
