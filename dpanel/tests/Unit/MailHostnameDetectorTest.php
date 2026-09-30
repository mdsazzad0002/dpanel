<?php

namespace Tests\Unit;

use App\Services\Mail\MailDnsRecords;
use App\Services\Mail\MailHostnameDetector;
use App\Services\ScriptExecutionGateway;
use Tests\TestCase;

class MailHostnameDetectorTest extends TestCase
{
    private array $executed = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://panel.example.com',
            'serverpanel.mail.server_ip' => '203.0.113.5',
            'serverpanel.mail.hostname' => 'old.example.net',
        ]);
    }

    public function test_a_ptr_name_that_resolves_back_is_preferred(): void
    {
        $detector = $this->detector('mail.example.com', [
            'mail.example.com' => ['203.0.113.5'],
            'mail.panel.example.com' => ['203.0.113.5'],
        ]);

        $this->assertSame('mail.example.com', $detector->best());
    }

    public function test_names_pointing_elsewhere_are_skipped(): void
    {
        // PTR does not resolve, the configured host is another server, the
        // panel domain is behind a proxy: only mail.<panel domain> is usable.
        $detector = $this->detector('vps1.provider.example', [
            'old.example.net' => ['198.51.100.9'],
            'mail.panel.example.com' => ['203.0.113.5'],
            'panel.example.com' => ['104.21.0.1'],
        ]);

        $this->assertSame('mail.panel.example.com', $detector->best());
        $this->assertFalse(collect($detector->candidates())->firstWhere('host', 'old.example.net')['usable']);
    }

    public function test_apply_refuses_a_host_that_is_not_this_server(): void
    {
        $detector = $this->detector('', ['other.example.org' => ['198.51.100.9']]);

        $result = $detector->apply('other.example.org');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->executed);
    }

    public function test_apply_best_forces_the_script(): void
    {
        $detector = $this->detector('', ['mail.panel.example.com' => ['203.0.113.5']]);

        $result = $detector->applyBest();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['changed']);
        $this->assertSame(['mail.panel.example.com', '--set'], $this->executed[0]);
    }

    /** @param  array<string, array<int, string>>  $dns */
    private function detector(string $ptr, array $dns): MailHostnameDetector
    {
        $test = $this;
        $gateway = new class($test) extends ScriptExecutionGateway
        {
            public function __construct(private $test) {}

            public function execute(string $scriptPath, array $arguments = [], array $environment = [], bool $asRoot = false): array
            {
                $this->test->record($arguments);

                return ['success' => true, 'output' => "MAIL_HOSTNAME={$arguments[0]}\nMAIL_HOSTNAME_CHANGED=1\n", 'exit_code' => 0, 'ran' => true, 'api_url' => '', 'script_path' => $scriptPath];
            }
        };

        return new MailHostnameDetector(new MailDnsRecords, $gateway, fn (string $host) => $dns[$host] ?? [], fn () => $ptr);
    }

    public function record(array $arguments): void
    {
        $this->executed[] = $arguments;
    }
}
