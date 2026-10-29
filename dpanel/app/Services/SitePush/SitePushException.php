<?php

namespace App\Services\SitePush;

use RuntimeException;

class SitePushException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
