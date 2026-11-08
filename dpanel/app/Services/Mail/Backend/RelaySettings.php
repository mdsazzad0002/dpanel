<?php

namespace App\Services\Mail\Backend;

final class RelaySettings
{
    public function __construct(
        public readonly string $host,
        public readonly int $port = 587,
        public readonly string $username = '',
        #[\SensitiveParameter]
        public readonly string $password = '',
    ) {}
}
