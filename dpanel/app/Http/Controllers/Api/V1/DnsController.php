<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DnsZone;
use App\Services\Dns\DnsRecordContent;
use App\Services\Dns\DnsRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DNS through the public API: the zones a user may see (DnsZone::visibleTo)
 * and their records. Records change through DnsRecordService, as on the DNS
 * page. A record's id is its PowerDNS record id.
 */
class DnsController extends ApiController
{
    public function __construct(private readonly DnsRecordService $records) {}

    public function zones(Request $request): JsonResponse
    {
        $zones = DnsZone::query()->visibleTo($request->user())->whereNotNull('powerdns_domain_id')->orderBy('domain')->get();

        return response()->json(['data' => $zones->map(fn (DnsZone $zone) => [
            'id' => (string) $zone->id,
            'domain' => (string) $zone->domain,
            'status' => (string) $zone->status,
            'dnssec_enabled' => (bool) $zone->dnssec_enabled,
            'owner_id' => $zone->owner_user_id,
            'website_id' => $zone->website_id,
        ])->values()]);
    }

    public function index(Request $request, string $zone): JsonResponse
    {
        $profile = $this->zone($request, $zone);
        if ($profile === null) {
            return $this->notFound('DNS zone');
        }

        return response()->json(['data' => array_map(fn (array $row) => $this->present($row, $profile), $this->records->list((int) $profile->powerdns_domain_id))]);
    }

    public function store(Request $request, string $zone): JsonResponse
    {
        $profile = $this->zone($request, $zone);
        if ($profile === null) {
            return $this->notFound('DNS zone');
        }
        $input = $request->validate($this->rules()) + ['status' => 'active'];

        $row = $this->records->add((int) $profile->powerdns_domain_id, (string) $profile->domain, $input);

        return response()->json(['message' => 'DNS record created.', 'data' => $this->present($row, $profile)], 201);
    }

    public function update(Request $request, string $zone, string $record): JsonResponse
    {
        $profile = $this->zone($request, $zone);
        $current = $profile ? $this->record($profile, $record) : null;
        if ($current === null) {
            return $this->notFound('DNS record');
        }
        // Fields left out keep their current value.
        $input = $request->validate(array_map(fn (array $rules) => array_map(fn ($rule) => $rule === 'required' ? 'sometimes' : $rule, $rules), $this->rules())) + [
            'type' => (string) $current->type,
            'name' => (string) $current->name,
            'content' => (string) $current->content,
            'ttl' => (int) $current->ttl,
            'priority' => $current->prio !== null ? (int) $current->prio : null,
            'status' => (int) $current->disabled === 1 ? 'disabled' : 'active',
        ];
        $zoneId = (int) $profile->powerdns_domain_id;

        $row = $this->records->update((int) $current->id, $zoneId, $zoneId, (string) $profile->domain, $input);

        return response()->json(['message' => 'DNS record updated.', 'data' => $this->present($row, $profile)]);
    }

    public function destroy(Request $request, string $zone, string $record): JsonResponse
    {
        $profile = $this->zone($request, $zone);
        $current = $profile ? $this->record($profile, $record) : null;
        if ($current === null) {
            return $this->notFound('DNS record');
        }

        $this->records->delete((int) $current->id, (int) $profile->powerdns_domain_id);

        return response()->json(['message' => 'DNS record deleted.']);
    }

    /** @return array<string, list<string>> the DNS page's rules for one record */
    private function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', DnsRecordContent::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:2048'],
            'ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['in:active,disabled'],
        ];
    }

    /** A zone by its domain name or id, when the user may see it. */
    private function zone(Request $request, string $zone): ?DnsZone
    {
        return DnsZone::query()
            ->visibleTo($request->user())
            ->whereNotNull('powerdns_domain_id')
            ->where(fn ($query) => $query->where('domain', strtolower(rtrim($zone, '.')))->orWhere('id', $zone))
            ->first();
    }

    /** A record of the zone, never its SOA (the zone's settings keep that). */
    private function record(DnsZone $profile, string $record): ?object
    {
        if (! ctype_digit($record)) {
            return null;
        }

        return DB::connection()->table('records')
            ->where('id', (int) $record)
            ->where('domain_id', (int) $profile->powerdns_domain_id)
            ->where('type', '!=', 'SOA')
            ->first();
    }

    /** @param array{id: int, name: string, type: string, content: string, ttl: int, priority: ?int, status: string} $row */
    private function present(array $row, DnsZone $profile): array
    {
        return [
            'id' => $row['id'],
            'name' => DnsRecordService::relativeName($row['name'], (string) $profile->domain),
            'fqdn' => $row['name'],
            'type' => $row['type'],
            'content' => $row['content'],
            'ttl' => $row['ttl'],
            'priority' => $row['priority'],
            'status' => $row['status'],
        ];
    }
}
