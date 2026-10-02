<?php

namespace Tests\Unit;

use App\Services\Dns\PublicDnsLookup;
use App\Services\Mail\MailDomainProvisioner;
use App\Services\Mail\MailHostnameDetector;
use App\Services\ScriptExecutionGateway;
use Tests\Support\FakeDns;
use Tests\TestCase;

class MailDomainProvisionerTest extends TestCase
{
    private array $executed = [];

    public function test_the_fallback_hostname_uses_the_panel_domain_not_the_customer_domain(): void
    {
        config([
            'app.url' => 'https://panel.example.com',
            'serverpanel.mail.server_ip' => '203.0.113.5',
            'serverpanel.mail.hostname' => '',
        ]);
        $this->app->instance(PublicDnsLookup::class, new FakeDns([]));
        $gateway = $this->gateway();
        $this->app->instance(ScriptExecutionGateway::class, $gateway);
        $this->app->instance(MailHostnameDetector::class, new class(app(\App\Services\Mail\MailDnsRecords::class), $gateway, new FakeDns([])) extends MailHostnameDetector
        {
            public function current(): string
            {
                return 'localhost';
            }
        });

        $result = (new MailDomainProvisioner($gateway))->ensureServerHostname('customer-shop.test');

        $this->assertTrue($result['ok']);
        $this->assertSame(['mail.panel.example.com'], $this->executed[0]);
    }

    private function gateway(): ScriptExecutionGateway
    {
        $test = $this;

        return new class($test) extends ScriptExecutionGateway
        {
            public function __construct(private $test) {}

            public function execute(string $scriptPath, array $arguments = [], array $environment = [], bool $asRoot = false): array
            {
                $this->test->record($arguments);

                return ['success' => true, 'output' => "MAIL_HOSTNAME={$arguments[0]}\nMAIL_HOSTNAME_CHANGED=1\n", 'exit_code' => 0, 'ran' => true, 'api_url' => '', 'script_path' => $scriptPath];
            }
        };
    }

    public function record(array $arguments): void
    {
        $this->executed[] = $arguments;
    }
}
