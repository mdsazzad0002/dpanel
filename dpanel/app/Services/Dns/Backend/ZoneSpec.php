<?php

namespace App\Services\Dns\Backend;

final class ZoneSpec
{
    /** @param  'master'|'slave'|'native'  $kind */
    public function __construct(
        public readonly string $name,
        public readonly string $kind = 'master',
        public readonly ?string $primary = null,
    ) {
        if (! in_array($kind, ['master', 'slave', 'native'], true)) {
            throw new \InvalidArgumentException("Unknown zone kind {$kind}.");
        }
    }
}
