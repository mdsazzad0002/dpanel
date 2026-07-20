<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhpMyAdminRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_phpmyadmin_routes_do_not_use_php_in_the_path(): void
    {
        $token = str_repeat('a', 64);

        $this->assertSame(
            '/cpsess'.$token.'/phpmyadmin/databases',
            route('phpmyadmin.databases', ['token' => $token], false)
        );
        $this->assertStringNotContainsString('.php', route('phpmyadmin.databases', ['token' => $token], false));
    }

    public function test_database_list_contains_only_database_names(): void
    {
        $user = User::factory()->create();
        $token = str_repeat('c', 64);

        $response = $this
            ->withoutMiddleware()
            ->actingAs($user)
            ->withSession(['panel_session_token' => $token])
            ->get('/cpsess'.$token.'/phpmyadmin/databases');

        $response->assertOk();

        $databases = $response->json('databases');
        $this->assertIsArray($databases);

        foreach ($databases as $database) {
            $this->assertIsString($database);
            $this->assertNotSame('', trim($database));
        }
    }
}
