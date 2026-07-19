<?php

namespace Tests\Unit;

use App\Services\Php\PhpService;
use Tests\TestCase;

class PhpServiceTest extends TestCase
{
    public function test_get_php_versions_comes_from_config(): void
    {
        config()->set('serverpanel.php_versions', ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5']);

        $versions = PhpService::getPhpVersions();

        $this->assertSame(['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'], $versions);
    }
}
