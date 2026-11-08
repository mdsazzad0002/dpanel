<?php

namespace App\Services\Mail\Backend;

final class AutoresponderData
{
    /** Dates are `Y-m-d`, inclusive, in the server's time. */
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly int $days = 1,
        public readonly ?string $startsOn = null,
        public readonly ?string $endsOn = null,
    ) {}
}
