<?php

namespace Tests\Unit;

use App\Services\Website\SiteOwnerIsolation;
use App\Support\Platform\LocalCommand;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SiteOwnerIsolationTest extends TestCase
{
    public function test_linux_runs_each_step_as_its_own_argv(): void
    {
        $ran = [];
        Process::fake(function (PendingProcess $process) use (&$ran) {
            $ran[] = $process->command;

            // The group is missing; the user already exists.
            return Process::result(exitCode: $process->command[0] === 'getent' ? 2 : 0);
        });

        (new SiteOwnerIsolation(new LocalCommand('Linux')))->apply("o'wner", '/home/o', '/home/o', '/home/o/public_html', '/home/o/public_html');

        $this->assertSame([
            ['getent', 'group', "o'wner"],
            ['groupadd', "o'wner"],
            ['id', '-u', "o'wner"],
            ['mkdir', '-p', '/home/o'],
            ['chown', 'root:root', '/home/o'],
            ['chmod', '711', '/home/o'],
            ['mkdir', '-p', '/home/o'],
            ['chown', '-R', "o'wner:www-data", '/home/o'],
            ['find', '/home/o', '-type', 'd', '-exec', 'chmod', '750', '{}', ';'],
            ['find', '/home/o', '-type', 'f', '-exec', 'chmod', '640', '{}', ';'],
            ['mkdir', '-p', '/home/o/public_html'],
            ['mkdir', '-p', '/home/o/public_html'],
        ], $ran);
    }

    public function test_other_systems_run_nothing(): void
    {
        Process::fake();

        foreach (['Windows', 'Darwin'] as $family) {
            $isolation = new SiteOwnerIsolation(new LocalCommand($family));
            $isolation->apply('owner', 'C:\\sites\\owner', 'C:\\sites\\owner', 'C:\\sites\\owner\\public_html', 'C:\\sites\\owner\\public_html');
            $isolation->lockHome('C:\\sites\\owner');
        }

        Process::assertNothingRan();
    }
}
