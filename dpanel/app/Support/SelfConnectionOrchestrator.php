<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SelfConnectionOrchestrator
{
    public function __construct(private readonly EnvFileWriter $writer)
    {
    }

    /**
     * Backs up .env, patches it with $envPatch, restarts the services that
     * hold a long-lived copy of the config, then verifies the LIVE app works
     * on the new values via $verify(). Rolls back automatically on any
     * failure so the app is never left in a partial/broken state.
     *
     * @param array<string, string> $envPatch
     * @param callable(): array{success: bool, error?: string} $verify
     * @return array{success: bool, stage?: string, error?: string, backup?: string, rolled_back?: bool}
     */
    public function apply(array $envPatch, callable $verify): array
    {
        try {
            $backupPath = $this->writer->backup((string) config('selfconnection.env_backup_dir'));
        } catch (\Throwable $e) {
            return ['success' => false, 'stage' => 'backup', 'error' => $e->getMessage()];
        }

        try {
            $newContents = $this->writer->patch($envPatch);
            $this->writer->writeAtomic($newContents);
        } catch (\Throwable $e) {
            return ['success' => false, 'stage' => 'write', 'error' => $e->getMessage(), 'backup' => $backupPath];
        }

        $restart = $this->restartServices();
        if (! $restart['success']) {
            $this->rollback($backupPath);
            return ['success' => false, 'stage' => 'restart', 'error' => $restart['error'], 'backup' => $backupPath, 'rolled_back' => true];
        }

        usleep(max(0, (int) config('selfconnection.restart_settle_ms', 500)) * 1000);

        $verifyResult = $verify();
        if (! ($verifyResult['success'] ?? false)) {
            $this->rollback($backupPath);
            return [
                'success' => false,
                'stage' => 'verify',
                'error' => $verifyResult['error'] ?? 'Post-apply verification failed.',
                'backup' => $backupPath,
                'rolled_back' => true,
            ];
        }

        $this->pruneOldBackups();

        return ['success' => true, 'backup' => $backupPath];
    }

    private function rollback(string $backupPath): void
    {
        try {
            $this->writer->restore($backupPath);
            $this->restartServices();
        } catch (\Throwable $e) {
            Log::critical('SelfConnectionOrchestrator rollback failed: '.$e->getMessage());
        }
    }

    /**
     * @return array{success: bool, error?: string}
     */
    private function restartServices(): array
    {
        $clear = $this->runPrivileged(['php', base_path('artisan'), 'config:clear']);
        if (! $clear->successful()) {
            return ['success' => false, 'error' => 'config:clear failed: '.$this->tail($clear)];
        }

        $fpm = $this->runPrivileged(['systemctl', 'reload-or-restart', (string) config('selfconnection.php_fpm_service')]);
        if (! $fpm->successful()) {
            return ['success' => false, 'error' => 'PHP-FPM restart failed: '.$this->tail($fpm)];
        }

        foreach ((array) config('selfconnection.queue_supervisor_programs', []) as $program) {
            $program = (string) $program;
            if ($program === '') {
                continue;
            }

            $result = $this->runPrivileged(['/usr/local/sbin/dpanel-supervisor-restart', $program]);
            if (! $result->successful()) {
                Log::warning('SelfConnectionOrchestrator: failed to restart queue program '.$program.': '.$this->tail($result));
            }
        }

        return ['success' => true];
    }

    private function pruneOldBackups(): void
    {
        $dir = (string) config('selfconnection.env_backup_dir');
        $retention = max(1, (int) config('selfconnection.env_backup_retention', 20));

        $files = glob(rtrim($dir, '/').'/.env.*.bak') ?: [];
        if (count($files) <= $retention) {
            return;
        }

        usort($files, static fn (string $a, string $b) => filemtime($a) <=> filemtime($b));

        foreach (array_slice($files, 0, count($files) - $retention) as $file) {
            @unlink($file);
        }
    }

    private function runPrivileged(array $command)
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return Process::timeout(30)->run($command);
        }

        return Process::timeout(30)->run(array_merge(['sudo', '-n'], $command));
    }

    private function tail($result): string
    {
        $output = trim($result->errorOutput() ?: $result->output());

        return $output !== '' ? substr($output, -500) : 'exit code '.$result->exitCode();
    }
}
