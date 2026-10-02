<?php

namespace App\Http\Controllers;

use App\Models\DnsZone;
use App\Services\Dns\BindZoneFile;
use App\Services\Dns\DnsRecordContent;
use App\Services\Dns\DnsZoneImporter;
use App\Services\Dns\ZoneRecordScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Exports a zone as a BIND file, and imports records from a zone file or a scan of public DNS. */
class DnsZoneFileController extends Controller
{
    public function export(Request $request, string $token, string $id, BindZoneFile $zoneFile): Response
    {
        $profile = $this->authorizedZone($request, $id);
        $zoneId = (int) $profile->powerdns_domain_id;
        $domain = (string) DB::table('domains')->where('id', $zoneId)->value('name');
        abort_if($domain === '', 404);

        $records = DB::table('records')
            ->where('domain_id', $zoneId)
            ->where('disabled', 0)
            ->get(['name', 'type', 'content', 'ttl', 'prio']);

        return response($zoneFile->export($domain, $records), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$domain.'.zone"',
        ]);
    }

    public function import(Request $request, string $token, string $id, BindZoneFile $zoneFile, DnsZoneImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'zone_file' => ['required_without:file', 'nullable', 'string', 'max:1048576'],
            'file' => ['required_without:zone_file', 'nullable', 'file', 'max:1024'],
            'replace' => ['boolean'],
        ]);
        [$profile, $domain] = $this->zone($request, $id);

        $text = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $validated['zone_file'];
        ['records' => $parsed, 'skipped' => $skipped] = $zoneFile->parse($text, $domain);
        if ($parsed === []) {
            throw ValidationException::withMessages(['zone_file' => 'No records for '.$domain.' were found in this file.'.($skipped !== [] ? ' '.$skipped[0] : '')]);
        }

        $result = $importer->import($profile, $domain, $parsed, $request->boolean('replace'));

        return $this->imported($domain, $result['created'], array_merge($skipped, $result['skipped']));
    }

    /** Looks the domain's current records up in public DNS, for review. */
    public function scan(Request $request, string $token, string $id, ZoneRecordScanner $scanner): JsonResponse
    {
        [$profile, $domain] = $this->zone($request, $id);
        $result = $scanner->scan($domain, (int) config('dns.default_ttl', 3600));
        $existing = DB::table('records')->where('domain_id', (int) $profile->powerdns_domain_id)
            ->get(['name', 'type'])
            ->map(fn ($row) => strtoupper((string) $row->type).' '.$row->name)
            ->flip();
        $ours = collect((array) config('dns.our_nameservers', []))->map(fn ($ns) => strtolower(trim((string) $ns)));

        return response()->json([
            'domain' => $domain,
            'nameservers' => $result['nameservers'],
            'our_nameservers' => $ours->filter()->values()->all(),
            'uses_our_nameservers' => $result['nameservers'] !== [] && collect($result['nameservers'])->every(fn ($ns) => $ours->contains($ns)),
            'wildcard' => $result['wildcard'],
            'records' => array_map(fn (array $record) => $record + [
                // A name the zone already has records of this type for.
                'exists' => $existing->has($record['type'].' '.$record['name']),
            ], $result['records']),
        ]);
    }

    /** Imports the records picked from a scan. */
    public function importRecords(Request $request, string $token, string $id, DnsZoneImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:1000'],
            'records.*.name' => ['required', 'string', 'max:253'],
            'records.*.type' => ['required', 'in:'.implode(',', DnsRecordContent::TYPES)],
            'records.*.content' => ['required', 'string', 'max:4096'],
            'records.*.priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'records.*.ttl' => ['required', 'integer', 'min:60', 'max:86400'],
        ]);
        [$profile, $domain] = $this->zone($request, $id);
        $result = $importer->import($profile, $domain, $validated['records']);

        return $this->imported($domain, $result['created'], $result['skipped']);
    }

    /** @param  list<string>  $skipped */
    private function imported(string $domain, int $created, array $skipped): RedirectResponse
    {
        $message = "Imported {$created} record".($created === 1 ? '' : 's')." into {$domain}.";
        if ($skipped !== []) {
            $message .= ' Skipped '.count($skipped).': '.implode(' | ', array_slice($skipped, 0, 3)).(count($skipped) > 3 ? ' …' : '');
        }

        return redirect()->route('dns.zones')->with($created > 0 ? 'success' : 'error', $message);
    }

    /** @return array{0: DnsZone, 1: string} */
    private function zone(Request $request, string $id): array
    {
        $profile = $this->authorizedZone($request, $id);
        $domain = strtolower((string) DB::table('domains')->where('id', (int) $profile->powerdns_domain_id)->value('name'));
        abort_if($domain === '', 404);

        return [$profile, $domain];
    }

    private function authorizedZone(Request $request, string $id): DnsZone
    {
        return DnsZone::query()
            ->visibleTo($request->user())
            ->where(fn ($query) => $query->where('id', $id)->when(ctype_digit($id), fn ($inner) => $inner->orWhere('powerdns_domain_id', (int) $id)))
            ->whereNotNull('powerdns_domain_id')
            ->firstOrFail();
    }
}
