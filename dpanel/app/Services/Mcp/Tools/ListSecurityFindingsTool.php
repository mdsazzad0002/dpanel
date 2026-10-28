<?php

namespace App\Services\Mcp\Tools;

use App\Models\McpAccessToken;
use App\Models\SecurityEvent;
use App\Models\SecurityFinding;
use App\Models\Website;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;

class ListSecurityFindingsTool implements McpTool
{
    private const SEVERITY_ORDER = "FIELD(severity, 'critical', 'high', 'medium', 'low', 'info')";

    public function name(): string
    {
        return 'list_security_findings';
    }

    public function title(): string
    {
        return 'Security findings';
    }

    public function description(): string
    {
        return 'Open findings from the panel\'s security scanner (malware, vulnerable code, weak config), most severe first, with file, line, evidence and recommended fix; plus the last 24 hours of security events (blocked requests, brute force) grouped by type and top source IPs. Use it for "is my site hacked?" or after suspicious traffic.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => ['type' => 'string', 'description' => 'Only this website.'],
                'severity' => ['type' => 'string', 'description' => 'Only this severity: critical, high, medium, low.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30],
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
        $severity = mb_strtolower(trim((string) ($arguments['severity'] ?? '')));
        $sites = Website::query()->visibleTo($token->user)
            ->when($domain !== '', fn ($q) => $q->whereIn('domain', [$domain, preg_replace('/^www\./', '', $domain)]))
            ->pluck('domain', 'id');

        if ($domain !== '' && $sites->isEmpty()) {
            throw new McpToolException("No website found for {$domain}. Call list_websites to search.");
        }
        $scoped = $domain !== '' || ! $token->user?->hasRole('admin');

        $findings = SecurityFinding::query()
            ->where('status', 'open')
            ->when($scoped, fn ($q) => $q->whereIn('website_id', $sites->keys()))
            ->when($severity !== '', fn ($q) => $q->where('severity', $severity))
            ->when(SecurityFinding::query()->getConnection()->getDriverName() === 'mysql', fn ($q) => $q->orderByRaw(self::SEVERITY_ORDER))
            ->latest('last_seen_at');

        $events = SecurityEvent::query()
            ->where('created_at', '>=', now()->subDay())
            ->when($scoped, fn ($q) => $q->whereIn('website_id', $sites->keys()));

        return [
            'open_by_severity' => (clone $findings)->reorder()->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity'),
            'findings' => $findings->limit(max(1, min(100, (int) ($arguments['limit'] ?? 30))))->get()
                ->map(fn (SecurityFinding $f) => [
                    'id' => $f->id,
                    'website' => $sites[$f->website_id] ?? $f->website_id,
                    'severity' => $f->severity,
                    'category' => $f->category,
                    'title' => $f->title,
                    'file' => $f->file_path,
                    'line' => $f->line_number,
                    'evidence' => $f->evidence ? mb_substr((string) $f->evidence, 0, 300) : null,
                    'recommendation' => $f->recommendation,
                    'status' => $f->status,
                    'last_seen_at' => $f->last_seen_at?->toIso8601String(),
                ])->values()->all(),
            'events_24h' => [
                'by_type' => (clone $events)->selectRaw('event_type, count(*) as total')->groupBy('event_type')->orderByDesc('total')->limit(15)->pluck('total', 'event_type'),
                'top_source_ips' => (clone $events)->whereNotNull('source_ip')->selectRaw('source_ip, count(*) as total')->groupBy('source_ip')->orderByDesc('total')->limit(10)->pluck('total', 'source_ip'),
            ],
        ];
    }
}
