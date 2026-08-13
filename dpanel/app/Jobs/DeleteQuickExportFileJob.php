<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class DeleteQuickExportFileJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public string $path, public string $downloadToken)
    {
    }

    public function handle(): void
    {
        File::delete($this->path);
        Cache::forget('quick-export-download:'.$this->downloadToken);
    }
}
