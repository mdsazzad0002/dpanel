<?php

namespace Tests\Unit;

use App\Services\Backup\WebsiteArchiver;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class WebsiteArchiverRetryTest extends TestCase
{
    public function test_only_refused_or_dropped_connections_count_as_a_drust_restart(): void
    {
        $this->assertTrue(WebsiteArchiver::isDrustRestart(new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 9500 after 0 ms')));
        $this->assertTrue(WebsiteArchiver::isDrustRestart(new ConnectionException('cURL error 52: Empty reply from server')));
        $this->assertFalse(WebsiteArchiver::isDrustRestart(new ConnectionException('cURL error 28: Operation timed out')));
        $this->assertFalse(WebsiteArchiver::isDrustRestart(new \RuntimeException('cURL error 7: x')));
    }
}
