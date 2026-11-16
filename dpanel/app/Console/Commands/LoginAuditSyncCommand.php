<?php

namespace App\Console\Commands;

use App\Models\LoginEvent;
use App\Services\LoginAudit\LoginAuditRecorder;
use App\Services\LoginAudit\SshLoginImporter;
use Illuminate\Console\Command;

class LoginAuditSyncCommand extends Command
{
    protected $signature = 'login-audit:sync';
    protected $description = 'Save new SSH logins from the journal, locate IPs that are still pending and drop rows past retention';

    public function handle(SshLoginImporter $ssh, LoginAuditRecorder $recorder): int
    {
        $failed = false;

        if ($ssh->supported()) {
            try {
                $this->info('SSH events saved: '.$ssh->import());
            } catch (\Throwable $e) {
                $failed = true;
                $this->error('SSH import failed: '.$e->getMessage());
            }
        }

        $this->info('Rows located: '.$recorder->locatePending());

        $days = max(1, (int) config('dpanel.login_audit_retention_days', 365));
        $pruned = LoginEvent::query()->where('occurred_at', '<', now()->subDays($days))->delete();
        $this->info("Rows older than {$days} days removed: {$pruned}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
