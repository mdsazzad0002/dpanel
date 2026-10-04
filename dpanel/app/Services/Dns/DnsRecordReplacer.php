<?php

namespace App\Services\Dns;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds text in record content across zones and replaces it, checking each
 * new value the same way the record form does. SOA records are left alone.
 */
class DnsRecordReplacer
{
    public const MODES = ['word', 'contains', 'exact'];

    /** The most matches one preview or apply handles. */
    public const LIMIT = 2000;

    public function __construct(
        private readonly DnsRecordContent $recordContent,
        private readonly DnsZoneRules $rules,
    ) {}

    /**
     * @param  Collection<int, DnsZone>  $profiles  zones the user may change
     * @param  array{find:string, replace:?string, mode:string, match_case:bool, types:list<string>}  $options
     * @param  list<int>|null  $onlyRecordIds  limit to these PowerDNS record ids
     * @return list<array{id:int, zone:string, type:string, name:string, before:string, after:string, priority:?int, error:?string}>
     */
    public function plan(Collection $profiles, array $options, ?array $onlyRecordIds = null): array
    {
        $domains = DB::table('domains')->whereIn('id', $profiles->pluck('powerdns_domain_id')->filter()->all())->pluck('name', 'id');
        if ($domains->isEmpty()) {
            return [];
        }
        $pattern = $this->pattern($options);
        $replace = (string) ($options['replace'] ?? '');

        $rows = DB::table('records')
            ->whereIn('domain_id', $domains->keys()->all())
            ->where('type', '!=', 'SOA')
            ->when($options['types'] !== [], fn ($query) => $query->whereIn('type', $options['types']))
            ->when($onlyRecordIds !== null, fn ($query) => $query->whereIn('id', $onlyRecordIds))
            ->orderBy('domain_id')->orderBy('name')->orderBy('type')->orderBy('id')
            ->get(['id', 'domain_id', 'name', 'type', 'content', 'prio']);

        $changes = [];
        foreach ($rows as $row) {
            $before = (string) $row->content;
            if (preg_match($pattern, $before) !== 1) {
                continue;
            }
            $zone = strtolower((string) $domains->get($row->domain_id));
            $type = strtoupper((string) $row->type);
            $raw = (string) preg_replace_callback($pattern, fn () => $replace, $before);
            $after = $raw;
            $priority = $row->prio !== null ? (int) $row->prio : null;
            $error = null;
            try {
                ['content' => $after, 'priority' => $normalizedPriority] = $this->recordContent->normalize($type, $raw, $priority, $zone);
                if (in_array($type, ['MX', 'SRV'], true)) {
                    $priority = $normalizedPriority;
                }
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
            if ($error === null && $after === $before) {
                continue;
            }
            $changes[] = [
                'id' => (int) $row->id,
                'zone' => $zone,
                'type' => $type,
                'name' => (string) $row->name,
                'before' => $before,
                'after' => $after,
                'priority' => $priority,
                'error' => $error,
            ];
            if (count($changes) >= self::LIMIT) {
                break;
            }
        }

        return $changes;
    }

    /**
     * @param  Collection<int, DnsZone>  $profiles
     * @param  array{find:string, replace:?string, mode:string, match_case:bool, types:list<string>}  $options
     * @param  list<int>  $recordIds  the previewed changes the user kept
     * @return array{updated:int, zones:int, skipped:list<string>}
     */
    public function apply(Collection $profiles, array $options, array $recordIds): array
    {
        $updated = 0;
        $skipped = [];
        $touched = [];

        DB::transaction(function () use ($profiles, $options, $recordIds, &$updated, &$skipped, &$touched): void {
            foreach ($this->plan($profiles, $options, $recordIds) as $change) {
                $label = "{$change['type']} {$change['name']}";
                if ($change['error'] !== null) {
                    $skipped[] = "{$label}: {$change['error']}";

                    continue;
                }
                $record = DB::table('records')->where('id', $change['id'])->first(['domain_id', 'name', 'type']);
                $duplicate = DB::table('records')->where('domain_id', $record->domain_id)->where('name', $record->name)
                    ->where('type', $record->type)->where('content', $change['after'])->where('id', '!=', $change['id'])->exists();
                if ($duplicate) {
                    $skipped[] = "{$label}: the zone already has this record with {$change['after']}.";

                    continue;
                }

                DB::table('records')->where('id', $change['id'])->update(['content' => $change['after'], 'prio' => $change['priority']]);
                DnsRecord::query()->where('powerdns_record_id', $change['id'])->update(['content' => $change['after'], 'priority' => $change['priority']]);
                $touched[(int) $record->domain_id] = true;
                $updated++;
            }
            foreach (array_keys($touched) as $zoneId) {
                $this->rules->bumpSerial(DB::connection(), $zoneId);
            }
        });

        return ['updated' => $updated, 'zones' => count($touched), 'skipped' => $skipped];
    }

    /** @param  array{find:string, mode:string, match_case:bool}  $options */
    private function pattern(array $options): string
    {
        $find = preg_quote((string) $options['find'], '/');
        $body = match ($options['mode']) {
            // Not part of a longer name or address: 1.2.3.4 does not match 1.2.3.45.
            'word' => '(?<![A-Za-z0-9_-])'.$find.'(?![A-Za-z0-9_-])',
            'exact' => '^'.$find.'$',
            default => $find,
        };

        return '/'.$body.'/u'.($options['match_case'] ? '' : 'i');
    }
}
