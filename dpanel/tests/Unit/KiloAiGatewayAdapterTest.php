<?php

namespace Tests\Unit;

use App\Models\AiGatewayProvider;
use App\Services\AiGateway\AiGatewayService;
use Tests\TestCase;

class KiloAiGatewayAdapterTest extends TestCase
{
    public function test_kilo_is_registered_as_an_openai_compatible_driver(): void
    {
        $gateway = $this->app->make(AiGatewayService::class);
        $registered = $gateway->adapters()['kilo'];

        $this->assertSame('Kilo Code (500+ models)', $registered->label);
        $this->assertSame('https://api.kilo.ai/api/gateway', $registered->baseUrl);
        $this->assertTrue($gateway->adapterFor('kilo')->supportsDriver('kilo'));
    }

    public function test_kilo_has_a_display_label_and_default_model_seed(): void
    {
        $provider = new AiGatewayProvider(['driver' => 'kilo']);

        $this->assertSame('Kilo Code', $provider->getDriverLabel());
        $this->assertNotEmpty(config('aigateway.driver_default_models.kilo'));
    }
}
