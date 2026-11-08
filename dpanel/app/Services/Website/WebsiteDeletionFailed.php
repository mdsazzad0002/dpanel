<?php

namespace App\Services\Website;

/** A website deletion step failed; `key` names the step for the response. */
class WebsiteDeletionFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly string $key, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
