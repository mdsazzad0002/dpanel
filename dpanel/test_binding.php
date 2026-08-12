<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Server;
use App\Models\AiGatewayProvider;
use Illuminate\Support\Facades\Route;

class TokenParamTest extends TestCase
{
    public function test_provider_test_route_model_binding()
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $provider = AiGatewayProvider::create([
            'name' => 'Test Provider',
            'driver' => 'openai',
            'slug' => 'test-provider-' . \Str::random(4),
            'is_active' => true,
            'weight' => 1,
        ]);

        $token = \Str::random(64);
        $url = "/cpsess{$token}/ai-gateway/providers/{$provider->id}/test";

        $response = $this->post($url);
        echo "ai-gateway providers/{provider}/test response status: " . $response->status() . PHP_EOL;
        echo "ai-gateway providers/{provider}/test response content: " . $response->content() . PHP_EOL;

        $response->assertSessionHasNoErrors();
    }

    public function test_server_show_route_model_binding()
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $server = Server::factory()->create();
        $token = \Str::random(64);
        $url = "/cpsess{$token}/servers/{$server->id}";

        $response = $this->get($url);
        echo "servers/{server} response status: " . $response->status() . PHP_EOL;
        if ($response->status() >= 400) {
            echo "servers/{server} response content: " . $response->content() . PHP_EOL;
        }
    }
}



