<?php

namespace Tests\Unit;

use Tests\TestCase;

class DpanelProfileConfigTest extends TestCase
{
    public function test_the_profile_defaults_to_server(): void
    {
        $this->assertSame('server', config('app.profile'));
    }
}
