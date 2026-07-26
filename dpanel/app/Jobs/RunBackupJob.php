<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunBackupJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public string $backupId,
    ) {
    }

    public function handle(SshClientService $sshClient): void
    {
        $backup = Backup::find($this->backupId);
        if (! $backup) {
            return;
        }

        $backup->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            match ($backup->type) {
                'website' => $this->backupWebsite($backup, $sshClient),
                'database' => $this->backupDatabase($backup, $sshClient),
                'mail' => $this->backupMail($backup, $sshClient),
                'dns' => $this->backupDns($backup, $sshClient),
                'config' => $this->backupConfig($backup, $sshClient),
                default => throw new \RuntimeException("Unknown backup type: {$backup->type}"),
            };

            $backup->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    private function backupWebsite(Backup $backup, SshClientService $sshClient): void
    {
        // Archive website files
        // Store in configured storage
    }

    private function backupDatabase(Backup $backup, SshClientService $sshClient): void
    {
        // Dump database
        // Store in configured storage
    }

    private function backupMail(Backup $backup, SshClientService $sshClient): void
    {
        // Archive maildir
        // Store in configured storage
    }

    private function backupDns(Backup $backup, SshClientService $sshClient): void
    {
        // Export DNS zones
        // Store in configured storage
    }

    private function backupConfig(Backup $backup, SshClientService $sshClient): void
    {
        // Archive config files
        // Store in configured storage
    }
}
