<?php

namespace App\Services\PanelUpdate;

/** An update that cannot start or be read, with the HTTP status the page gets. */
class PanelUpdateRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
