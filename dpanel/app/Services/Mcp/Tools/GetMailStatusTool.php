<?php

namespace App\Services\Mcp\Tools;

use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\McpAccessToken;
use App\Services\Mail\MailDeliveryDiagnosticsService;
use App\Services\Mcp\Contracts\McpTool;

class GetMailStatusTool implements McpTool
{
    public function __construct(private readonly MailDeliveryDiagnosticsService $diagnostics) {}

    public function name(): string
    {
        return 'get_mail_status';
    }

    public function title(): string
    {
        return 'Mail status';
    }

    public function description(): string
    {
        return 'Mail health on the panel host: delivery rate and counts (sent, bounced, deferred) from the recent mail log, the latest delivery failures with their classified cause, the Postfix queue, and each mail domain with its DKIM/SPF/DMARC settings and mailbox count. Use it when mail is not sent or not received; then check the domain\'s DNS with list_dns_records.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'Only this mail domain, and failures that mention it.'],
                'failures' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 20, 'description' => 'How many recent failures to return.'],
            ],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        $limit = max(0, min(100, (int) ($arguments['failures'] ?? 20)));
        $snapshot = $this->diagnostics->snapshot();

        $failures = collect($snapshot['failures'] ?? [])
            ->when($domain !== '', fn ($all) => $all->filter(fn ($failure) => str_contains(mb_strtolower((string) json_encode($failure)), $domain)))
            ->take($limit)
            ->values()
            ->all();

        $mailboxes = Mailbox::query()->selectRaw('domain, count(*) as total')->groupBy('domain')->pluck('total', 'domain');
        $queue = $snapshot['queue'] ?? [];

        return [
            'health_score' => $snapshot['health_score'] ?? null,
            'delivery_rate_percent' => $snapshot['delivery_rate'] ?? null,
            'stats' => $snapshot['stats'] ?? null,
            'queue' => [
                'available' => $queue['available'] ?? false,
                'count' => $queue['count'] ?? 0,
                'size_bytes' => $queue['size_bytes'] ?? 0,
                'error' => $queue['error'] ?? null,
                'oldest_messages' => array_slice($queue['messages'] ?? [], 0, 10),
            ],
            'recent_failures' => $failures,
            'domains' => MailDomain::query()
                ->when($domain !== '', fn ($q) => $q->where('domain', $domain))
                ->when(! $token->user?->hasRole('admin'), fn ($q) => $q->where('assigned_user_id', $token->user?->id))
                ->orderBy('domain')
                ->limit(200)
                ->get()
                ->map(fn (MailDomain $mail) => [
                    'domain' => $mail->domain,
                    'status' => $mail->status,
                    'dkim' => (bool) $mail->enable_dkim,
                    'dkim_selector' => $mail->dkim_selector,
                    'spf' => (bool) $mail->enable_spf,
                    'dmarc' => (bool) $mail->enable_dmarc,
                    'mailboxes' => (int) ($mailboxes[$mail->domain] ?? 0),
                ])->values()->all(),
            'log' => $snapshot['diagnostics'] ?? null,
        ];
    }
}
