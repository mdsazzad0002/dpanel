<?php

namespace App\Services\Mcp\Tools;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\McpAccessToken;
use App\Services\Dns\DnsRecordContent;
use App\Services\Dns\DnsRecordService;
use App\Services\Mcp\Contracts\McpTool;
use App\Services\Mcp\McpToolException;
use Illuminate\Validation\ValidationException;

/**
 * Add or delete one record of a zone hosted by the panel, through the same
 * service and checks as the DNS page and the public API.
 */
class ManageDnsRecordTool implements McpTool
{
    public function __construct(private readonly DnsRecordService $records) {}

    public function name(): string
    {
        return 'manage_dns_record';
    }

    public function title(): string
    {
        return 'Add or delete a DNS record';
    }

    public function description(): string
    {
        return 'Add (action=add) or delete (action=delete) one DNS record in a zone hosted by this panel. name is relative to the zone ("@" for the apex, "www", "mail") or the full name. Delete matches type and name, plus content when several records share them. Call list_dns_records first: it shows the zone, existing records and what is missing (SPF, DMARC, DKIM, www). Never delete the zone\'s NS records or the site\'s main A record unless the admin asked for exactly that.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['add', 'delete']],
                'zone' => ['type' => 'string', 'description' => 'Zone domain, e.g. example.com.'],
                'type' => ['type' => 'string', 'enum' => DnsRecordContent::TYPES],
                'name' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'ttl' => ['type' => 'integer', 'minimum' => 60, 'maximum' => 86400, 'default' => 3600],
                'priority' => ['type' => 'integer', 'description' => 'MX and SRV only.'],
            ],
            'required' => ['action', 'zone', 'type', 'name'],
        ];
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function handle(array $arguments, McpAccessToken $token): array
    {
        if (! $token->allowsMutations()) {
            throw new McpToolException('This token is read-only. Switch the chat\'s server tools to full access to change DNS.');
        }

        $zoneDomain = mb_strtolower(rtrim(trim((string) ($arguments['zone'] ?? '')), '.'));
        $zone = DnsZone::query()->visibleTo($token->user)->whereNotNull('powerdns_domain_id')->where('domain', $zoneDomain)->first();
        if ($zone === null) {
            throw new McpToolException("No DNS zone {$zoneDomain} on this panel that this account can edit. Call list_dns_records without a domain to see the zones.");
        }
        $type = strtoupper(trim((string) ($arguments['type'] ?? '')));
        if (! in_array($type, DnsRecordContent::TYPES, true)) {
            throw new McpToolException('type must be one of '.implode(', ', DnsRecordContent::TYPES).'.');
        }
        $name = $this->fullName(trim((string) ($arguments['name'] ?? '')), $zoneDomain);

        try {
            return match ((string) ($arguments['action'] ?? '')) {
                'add' => $this->add($zone, $type, $name, $arguments),
                'delete' => $this->delete($zone, $type, $name, (string) ($arguments['content'] ?? '')),
                default => throw new McpToolException('action must be add or delete.'),
            };
        } catch (ValidationException $e) {
            throw new McpToolException(implode(' ', collect($e->errors())->flatten()->all()));
        }
    }

    private function add(DnsZone $zone, string $type, string $name, array $arguments): array
    {
        $content = trim((string) ($arguments['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 2048) {
            throw new McpToolException('content is required to add a record.');
        }
        $ttl = max(60, min(86400, (int) ($arguments['ttl'] ?? 3600)));

        $row = $this->records->add((int) $zone->powerdns_domain_id, (string) $zone->domain, [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => isset($arguments['priority']) ? (int) $arguments['priority'] : null,
            'status' => 'active',
        ]);

        return ['added' => $row, 'note' => 'DNS changes can take up to the TTL to be seen by resolvers that cached the old answer.'];
    }

    private function delete(DnsZone $zone, string $type, string $name, string $content): array
    {
        if ($type === 'NS' && $name === $zone->domain) {
            throw new McpToolException('Refused: deleting the zone\'s own NS records would take the whole domain offline.');
        }

        $matches = DnsRecord::query()->where('dns_zone_id', $zone->id)->where('type', $type)->where('name', $name)
            ->when(trim($content) !== '', fn ($q) => $q->where('content', trim($content)))
            ->get();
        if ($matches->isEmpty()) {
            throw new McpToolException("No {$type} record named {$name}".(trim($content) !== '' ? ' with that content' : '').'.');
        }
        if ($matches->count() > 1) {
            throw new McpToolException("{$matches->count()} {$type} records are named {$name}. Give content to pick one: ".$matches->pluck('content')->map(fn ($c) => mb_substr((string) $c, 0, 120))->implode(' | '));
        }

        $record = $matches->first();
        $this->records->delete((int) $record->powerdns_record_id, (int) $zone->powerdns_domain_id);

        return ['deleted' => ['type' => $type, 'name' => $name, 'content' => $record->content, 'ttl' => $record->ttl]];
    }

    /** "@", "www" or "www.example.com." all become the record's full name. */
    private function fullName(string $name, string $zone): string
    {
        $name = mb_strtolower(rtrim($name, '.'));
        if ($name === '' || $name === '@' || $name === $zone) {
            return $zone;
        }

        return str_ends_with($name, '.'.$zone) ? $name : $name.'.'.$zone;
    }
}
