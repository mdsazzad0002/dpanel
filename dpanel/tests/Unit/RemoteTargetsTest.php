<?php

namespace Tests\Unit;

use App\Support\Proxy\RemoteTargets;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RemoteTargetsTest extends TestCase
{
    public function test_targets_are_normalized_and_deduplicated(): void
    {
        $this->assertSame(
            ['http://192.168.0.106', 'https://app.lan:8443', 'http://[fd00::5]:8080'],
            RemoteTargets::normalize(['192.168.0.106', 'HTTP://192.168.0.106/', ' https://App.Lan:8443/x ', 'http://[fd00::5]:8080', '']),
        );
        $this->assertSame(['http://10.0.0.2:80', 'http://10.0.0.3:80'], RemoteTargets::normalize('10.0.0.2:80, 10.0.0.3:80'));
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:3000'],
            'localhost' => ['localhost:8080'],
            'ipv6 loopback' => ['http://[::1]:80'],
            'metadata' => ['http://169.254.169.254'],
            'mapped metadata' => ['http://[::ffff:169.254.169.254]'],
            'unspecified' => ['http://0.0.0.0:80'],
            'scheme' => ['ftp://10.0.0.2'],
            'credentials' => ['http://user:pass@10.0.0.2'],
            'bad host' => ['http://bad_host!'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_unsafe_or_invalid_targets_are_rejected(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        RemoteTargets::normalize([$target]);
    }

    public function test_an_empty_list_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RemoteTargets::normalize(['', ' ']);
    }
}
