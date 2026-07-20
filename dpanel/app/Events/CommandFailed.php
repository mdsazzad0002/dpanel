<?php

namespace App\Events;

use App\Models\CommandJob;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommandFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public CommandJob $commandJob)
    {
    }
}
