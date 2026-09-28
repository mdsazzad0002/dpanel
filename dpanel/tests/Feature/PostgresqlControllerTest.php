<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PostgresqlServiceManager;
use Mockery\MockInterface;
use Tests\TestCase;

class PostgresqlControllerTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = str_repeat('d', 64);
    }

    public function test_toggle_turns_a_service_on_through_the_manager(): void
    {
        $this->mock(PostgresqlServiceManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('isKnownService')->with('pgadmin')->andReturnTrue();
            $mock->shouldReceive('setRunning')->once()->with('pgadmin', true)->andReturn(['success' => true]);
            $mock->shouldReceive('statuses')->andReturn([]);
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/postgresql/pgadmin/toggle", ['running' => true])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_toggle_reports_a_failure(): void
    {
        $this->mock(PostgresqlServiceManager::class, function (MockInterface $mock) {
            $mock->shouldReceive('isKnownService')->andReturnTrue();
            $mock->shouldReceive('setRunning')->once()->with('postgresql', false)->andReturn(['success' => false, 'error' => 'sudo: a password is required']);
            $mock->shouldReceive('statuses')->andReturn([]);
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/postgresql/postgresql/toggle", ['running' => false])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'sudo: a password is required']);
    }

    public function test_toggle_rejects_unknown_services(): void
    {
        $this->mock(PostgresqlServiceManager::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('setRunning');
        });

        $this->request()
            ->postJson("/cpsess{$this->token}/databases/postgresql/mariadb/toggle", ['running' => true])
            ->assertNotFound();
    }

    public function test_manager_only_accepts_configured_services(): void
    {
        $manager = new PostgresqlServiceManager();

        $this->assertTrue($manager->isKnownService('postgresql'));
        $this->assertTrue($manager->isKnownService('pgadmin'));
        $this->assertFalse($manager->isKnownService('mariadb'));
        $this->assertFalse($manager->setRunning('mariadb', true)['success']);
    }

    private function request(): static
    {
        return $this
            ->withoutMiddleware()
            ->actingAs(User::factory()->make())
            ->withSession(['panel_session_token' => $this->token]);
    }
}
