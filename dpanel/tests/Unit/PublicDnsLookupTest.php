<?php

namespace Tests\Unit;

use App\Services\Dns\PublicDnsLookup;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicDnsLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['serverpanel.execution_api_base_url' => 'http://drust.test', 'serverpanel.execution_api_token' => 'secret']);
    }

    public function test_answers_come_from_drust_in_one_call_and_are_reused(): void
    {
        Http::fake(['drust.test/api/v1/dns/lookup' => Http::response(['success' => true, 'data' => ['answers' => [
            ['name' => 'example.com', 'type' => 'MX', 'values' => ['10 mail.example.com'], 'error' => ''],
            ['name' => 'mail.example.com', 'type' => 'A', 'values' => ['203.0.113.5'], 'error' => ''],
        ]]])]);
        $dns = new PublicDnsLookup;

        $dns->prefetch([['example.com', 'MX'], ['mail.example.com', 'A']]);

        $this->assertSame(['mail.example.com'], $dns->mx('example.com'));
        $this->assertSame(['203.0.113.5'], $dns->a('mail.example.com'));
        Http::assertSentCount(1);
    }

    public function test_a_drust_error_is_a_failed_lookup_not_an_empty_record(): void
    {
        Http::fake(['drust.test/api/v1/dns/lookup' => Http::response(['success' => true, 'data' => ['answers' => [
            ['name' => 'example.com', 'type' => 'TXT', 'values' => [], 'error' => 'request timed out'],
            ['name' => 'nothing.example.com', 'type' => 'A', 'values' => [], 'error' => ''],
        ]]])]);
        $dns = new PublicDnsLookup;
        $dns->prefetch([['example.com', 'TXT'], ['nothing.example.com', 'A']]);

        $this->assertTrue($dns->failed('example.com', 'TXT'));
        $this->assertFalse($dns->failed('nothing.example.com', 'A'));
        $this->assertSame([], $dns->a('nothing.example.com'));
    }
}
