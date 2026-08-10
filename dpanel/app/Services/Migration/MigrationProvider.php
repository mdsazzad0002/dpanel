<?php

namespace App\Services\Migration;

interface MigrationProvider
{
    public function key(): string;
    public function inspect(string $archivePath): array;
    public function restore(string $archivePath, array $selection): array;
}
