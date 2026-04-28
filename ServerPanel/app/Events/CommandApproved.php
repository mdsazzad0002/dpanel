<?php

namespace App\Events;

use App\Models\CommandJob;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommandApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public CommandJob $commandJob)
    {
    }
}
