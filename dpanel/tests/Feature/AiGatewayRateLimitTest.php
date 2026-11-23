<?php

namespace Tests\Feature;

use App\Models\AiGatewayApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiGatewayRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function key(array $attrs = []): string
    {
        $generated = AiGatewayApiKey::generate();
        AiGatewayApiKey::query()->create([
            'name' => 'test', 'key_prefix' => $generated['prefix'], 'key_hash' => $generated['hash'], 'is_active' => true,
            ...$attrs,
        ]);

        return $generated['plain'];
    }

    private function models(string $plain, string $ip = '203.0.113.5')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson('/api/v1/models', ['Authorization' => 'Bearer '.$plain]);
    }

    public function test_per_key_per_ip_limit_returns_429_but_other_ips_still_work(): void
    {
        $plain = $this->key(['ip_rate_limit_per_minute' => 2, 'rate_limit_per_minute' => 0]);

        $this->models($plain)->assertOk();
        $this->models($plain)->assertOk();
        $this->models($plain)->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limit_exceeded')
            ->assertHeader('Retry-After');

        $this->models($plain, '198.51.100.7')->assertOk();
    }

    public function test_per_key_total_limit_covers_every_ip(): void
    {
        $plain = $this->key(['rate_limit_per_minute' => 2, 'ip_rate_limit_per_minute' => 0]);

        $this->models($plain, '203.0.113.1')->assertOk();
        $this->models($plain, '203.0.113.2')->assertOk();
        $this->models($plain, '203.0.113.3')->assertStatus(429);
    }

    public function test_per_ip_cap_applies_before_the_key_is_checked(): void
    {
        config(['aigateway.rate_limit.ip_per_minute' => 2, 'aigateway.rate_limit.bad_key_attempts' => 0]);

        $this->models('sk-ag-nope')->assertStatus(401);
        $this->models('sk-ag-nope')->assertStatus(401);
        $this->models('sk-ag-nope')->assertStatus(429);
    }

    public function test_ip_is_locked_out_after_too_many_bad_keys(): void
    {
        config(['aigateway.rate_limit.bad_key_attempts' => 3]);
        $good = $this->key();

        foreach (range(1, 3) as $_) {
            $this->models('sk-ag-wrong')->assertStatus(401);
        }

        // Even a valid key is refused from that IP during the lockout.
        $this->models($good)->assertStatus(429)->assertJsonPath('error.code', 'rate_limit_exceeded');
        $this->models($good, '198.51.100.7')->assertOk();
    }

    public function test_ip_allowlist(): void
    {
        $plain = $this->key(['allowed_ips' => "198.51.100.0/24\n2001:db8::1"]);

        $this->models($plain, '198.51.100.42')->assertOk();
        $this->models($plain, '2001:db8::1')->assertOk();
        $this->models($plain, '203.0.113.5')->assertStatus(403)->assertJsonPath('error.code', 'ip_not_allowed');
    }

    public function test_ip_list_validation(): void
    {
        $this->assertTrue(AiGatewayApiKey::isValidIpOrCidr('10.0.0.0/8'));
        $this->assertTrue(AiGatewayApiKey::isValidIpOrCidr('2001:db8::/32'));
        $this->assertFalse(AiGatewayApiKey::isValidIpOrCidr('10.0.0.0/33'));
        $this->assertFalse(AiGatewayApiKey::isValidIpOrCidr('example.com'));
        $this->assertSame(['1.2.3.4', '5.6.7.8'], AiGatewayApiKey::parseIpList("1.2.3.4, 5.6.7.8\n1.2.3.4"));
    }
}
