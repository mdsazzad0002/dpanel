<?php

namespace Tests\Support;

use App\Services\Dns\PublicDnsLookup;

/** Answers from a fixed table instead of the network. Keys: "TYPE name". */
class FakeDns extends PublicDnsLookup
{
    /** @param  array<string, array<int, string>>  $records */
    public function __construct(private readonly array $records)
    {
    }

    public function a(string $name): array
    {
        return $this->records['A '.$name] ?? [];
    }

    public function mx(string $name): array
    {
        return $this->records['MX '.$name] ?? [];
    }

    public function txt(string $name): array
    {
        return $this->records['TXT '.$name] ?? [];
    }

    public function ptr(string $ip): array
    {
        return $this->records['PTR '.$ip] ?? [];
    }

    public function prefetch(array $queries): void
    {
    }
}
