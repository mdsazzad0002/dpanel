<?php

namespace App\Services\ServerPanel;

use App\Models\CommandJob;
use Illuminate\Support\Facades\Storage;

class ReportService
{
    public function generate(CommandJob $job): string
    {
        $job->loadMissing(['server', 'events', 'approvedBy', 'requestedBy']);

        $date = now()->format('Y-m-d');
        $relativePath = trim((string) config('serverpanel.report_base_path', 'serverpanel/reports'), '/').'/'.$date.'/server-'.$job->server_id.'/command-'.$job->uuid.'.txt';

        $lines = [
            'ServerPanel Command Report',
            str_repeat('=', 80),
            'Server Name: '.($job->server->name ?? 'N/A'),
            'Host: '.($job->server->host ?? 'N/A'),
            'Command: '.$job->command,
            'Risk Level: '.$job->risk_level,
            'Status: '.$job->status,
            'Started At: '.optional($job->started_at)->toDateTimeString(),
            'Finished At: '.optional($job->finished_at)->toDateTimeString(),
            'Requested By: '.optional($job->requestedBy)->email,
            'Approved By: '.optional($job->approvedBy)->email,
            str_repeat('-', 80),
            'OUTPUT:',
            $job->output ?: '(none)',
            str_repeat('-', 80),
            'ERROR OUTPUT:',
            $job->error_output ?: '(none)',
            str_repeat('-', 80),
            'AI SUMMARY:',
            $job->ai_summary ?: '(none)',
            str_repeat('-', 80),
            'AI SUGGESTED FIX:',
            $job->ai_fix_suggestion ?: '(none)',
            str_repeat('-', 80),
            'EVENTS TIMELINE:',
        ];

        foreach ($job->events as $event) {
            $lines[] = sprintf('[%s] %s - %s', $event->created_at?->toDateTimeString(), $event->type, $event->message);
        }

        Storage::disk('local')->put($relativePath, implode(PHP_EOL, $lines).PHP_EOL);

        return $relativePath;
    }
}
