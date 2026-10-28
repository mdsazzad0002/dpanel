<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\SslCertificate;
use App\Services\Mcp\Contracts\McpTool;

class ListSslCertificatesTool implements McpTool
{
    public function name(): string
    {
        return 'list_ssl_certificates';
    }

    public function title(): string
    {
        return 'List SSL certificates';
    }

    public function description(): string
    {
        return 'SSL certificates known to the panel, soonest expiry first. Set expiring_within_days to find certificates that are expired or about to expire.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expiring_within_days' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Only certificates expiring within this many days (expired ones included).'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $limit = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
        $days = $arguments['expiring_within_days'] ?? null;

        $certificates = SslCertificate::query()
            ->when($days !== null, fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<', now()->addDays((int) $days)))
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        return [
            'certificates' => $certificates->map(fn (SslCertificate $cert) => [
                'domain' => $cert->domain,
                'type' => $cert->type,
                'challenge_type' => $cert->challenge_type,
                'status' => $cert->status,
                'expires_at' => $cert->expires_at?->toIso8601String(),
                'days_left' => $cert->expires_at ? (int) floor(now()->diffInDays($cert->expires_at, false)) : null,
                'auto_renew' => (bool) $cert->auto_renew,
                'certificate_path' => $cert->certificate_path,
            ])->values()->all(),
        ];
    }
}
