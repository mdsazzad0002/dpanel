<?php

namespace Tests\Unit;

use App\Support\Platform\ProcessIdentity;
use PHPUnit\Framework\TestCase;

class ProcessIdentityTest extends TestCase
{
    public function test_it_answers_on_this_machine_with_or_without_posix(): void
    {
        $identity = new ProcessIdentity;

        $this->assertSame(function_exists('posix_geteuid') ? posix_geteuid() : null, $identity->effectiveUserId());
        $this->assertSame($identity->effectiveUserId() === 0, $identity->isRoot());
        $this->assertNull($identity->account('no_such_account_x'));
        $this->assertNull($identity->userName(2147480000));
    }

    public function test_without_posix_nothing_is_known(): void
    {
        if (extension_loaded('posix')) {
            $this->markTestSkipped('This machine has ext-posix.');
        }

        $identity = new ProcessIdentity;

        $this->assertNull($identity->effectiveUserId());
        $this->assertFalse($identity->isRoot());
        $this->assertNull($identity->userName(0));
        $this->assertNull($identity->account('root'));
    }

    public function test_root_runs_the_command_as_it_is(): void
    {
        $this->assertSame(['ufw', 'status'], $this->identity(uid: 0, sudo: true)->privilegedCommand(['ufw', 'status']));
    }

    public function test_a_normal_user_goes_through_sudo(): void
    {
        $this->assertSame(['sudo', '-n', 'ufw', 'status'], $this->identity(uid: 1000, sudo: true)->privilegedCommand(['ufw', 'status']));
    }

    public function test_linux_without_posix_still_goes_through_sudo(): void
    {
        $this->assertSame(['sudo', '-n', 'ufw', 'status'], $this->identity(uid: null, sudo: true)->privilegedCommand(['ufw', 'status']));
    }

    public function test_a_system_without_sudo_runs_the_command_as_it_is(): void
    {
        $this->assertSame(['ufw', 'status'], $this->identity(uid: null, sudo: false)->privilegedCommand(['ufw', 'status']));
    }

    private function identity(?int $uid, bool $sudo): ProcessIdentity
    {
        return new class($uid, $sudo) extends ProcessIdentity
        {
            public function __construct(private ?int $uid, private bool $sudo) {}

            public function effectiveUserId(): ?int
            {
                return $this->uid;
            }

            protected function hasSudo(): bool
            {
                return $this->sudo;
            }
        };
    }
}
