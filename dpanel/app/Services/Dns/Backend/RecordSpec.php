<?php

namespace App\Services\Dns\Backend;

final class RecordSpec
{
    /** `$name` is the full name, without the trailing dot. */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $content,
        public readonly int $ttl = 3600,
        public readonly ?int $priority = null,
        public readonly bool $disabled = false,
    ) {}
}
