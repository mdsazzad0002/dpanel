<?php

namespace App\Jobs;

use App\Models\SslCertificate;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IssueSslCertificateJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(
        public string $certificateId,
        public string $action = 'issue',
    ) {
    }

    public function handle(SshClientService $sshClient): void
    {
        $cert = SslCertificate::find($this->certificateId);
        if (! $cert) {
            return;
        }

        match ($this->action) {
            'issue' => $this->issueCertificate($cert, $sshClient),
            'renew' => $this->renewCertificate($cert, $sshClient),
            'deploy' => $this->deployCertificate($cert, $sshClient),
            default => null,
        };
    }

    private function issueCertificate(SslCertificate $cert, SshClientService $sshClient): void
    {
        $cert->update(['status' => 'issuing']);

        try {
            // Use Let's Encrypt HTTP or DNS challenge
            // Store certificate paths
            $cert->update([
                'status' => 'issued',
                'issued_at' => now(),
                'expires_at' => now()->addDays(90),
            ]);
        } catch (\Throwable $e) {
            $cert->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function renewCertificate(SslCertificate $cert, SshClientService $sshClient): void
    {
        if (! $cert->isExpiringSoon()) {
            return;
        }

        $this->issueCertificate($cert, $sshClient);
        $cert->update(['renewed_at' => now()]);
    }

    private function deployCertificate(SslCertificate $cert, SshClientService $sshClient): void
    {
        // Deploy certificate to web server
        // Reload web server
    }
}
