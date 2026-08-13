<?php

namespace App\Services\Migration;

interface RemotePanelInventoryProvider
{
    /** @param array<string, mixed> $credentials */
    public function discover(array $credentials): array;
}
