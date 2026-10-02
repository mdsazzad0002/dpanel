<?php

namespace App\Http\Controllers;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\Dns\BindZoneFile;
use App\Services\Dns\DnsRecordContent;
use App\Services\Dns\DnsZoneRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Exports a zone as a BIND file and imports one, e.g. a Cloudflare export. */
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

    public function import(Request $request, string $token, string $id, BindZoneFile $zoneFile, DnsRecordContent $recordContent, DnsZoneRules $rules): RedirectResponse
    {
        $validated = $request->validate([
            'zone_file' => ['required_without:file', 'nullable', 'string', 'max:1048576'],
            'file' => ['required_without:zone_file', 'nullable', 'file', 'max:1024'],
            'replace' => ['boolean'],
        ]);
        $profile = $this->authorizedZone($request, $id);
        $zoneId = (int) $profile->powerdns_domain_id;
        $domain = strtolower((string) DB::table('domains')->where('id', $zoneId)->value('name'));
        abort_if($domain === '', 404);

        $text = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $validated['zone_file'];
        ['records' => $parsed, 'skipped' => $skipped] = $zoneFile->parse($text, $domain);
        if ($parsed === []) {
            throw ValidationException::withMessages(['zone_file' => 'No records for '.$domain.' were found in this file.'.($skipped !== [] ? ' '.$skipped[0] : '')]);
        }

        $created = 0;
        DB::transaction(function () use ($request, $parsed, $domain, $zoneId, $profile, $recordContent, $rules, &$created, &$skipped): void {
            if ($request->boolean('replace')) {
                // Keep SOA and the apex NS set; they describe this server.
                $kept = DB::table('records')->where('domain_id', $zoneId)
                    ->where(fn ($query) => $query->where('type', 'SOA')->orWhere(fn ($ns) => $ns->where('type', 'NS')->where('name', $domain)))
                    ->pluck('id');
                DB::table('records')->where('domain_id', $zoneId)->whereNotIn('id', $kept)->delete();
                DnsRecord::query()->where('dns_zone_id', $profile->id)->whereNotIn('powerdns_record_id', $kept)->delete();
            }

            foreach ($parsed as $record) {
                try {
                    $recordContent->assertValidName($record['name']);
                    ['content' => $content, 'priority' => $priority] = $recordContent->normalize($record['type'], $record['content'], $record['priority'], $domain);
                    $duplicate = DB::table('records')->where('domain_id', $zoneId)->where('name', $record['name'])
                        ->where('type', $record['type'])->where('content', $content)->exists();
                    if ($duplicate) {
                        continue;
                    }
                    $rules->assertNoCnameConflict(DB::connection(), $zoneId, $domain, $record['name'], $record['type']);
                } catch (\InvalidArgumentException $exception) {
                    $skipped[] = "{$record['type']} {$record['name']}: {$exception->getMessage()}";

                    continue;
                }

                $recordId = (int) DB::table('records')->insertGetId([
                    'domain_id' => $zoneId,
                    'name' => $record['name'],
                    'type' => $record['type'],
                    'content' => $content,
                    'ttl' => $record['ttl'],
                    'prio' => $priority,
                    'disabled' => 0,
                    'auth' => 1,
                ]);
                DnsRecord::query()->create([
                    'id' => (string) Str::uuid(),
                    'dns_zone_id' => $profile->id,
                    'powerdns_record_id' => $recordId,
                    'type' => $record['type'],
                    'name' => $record['name'],
                    'content' => $content,
                    'ttl' => $record['ttl'],
                    'priority' => $priority,
                    'is_active' => true,
                ]);
                $created++;
            }
            $rules->bumpSerial(DB::connection(), $zoneId);
        });

        $message = "Imported {$created} record".($created === 1 ? '' : 's')." into {$domain}.";
        if ($skipped !== []) {
            $message .= ' Skipped '.count($skipped).': '.implode(' | ', array_slice($skipped, 0, 3)).(count($skipped) > 3 ? ' …' : '');
        }

        return redirect()->route('dns.zones')->with($created > 0 ? 'success' : 'error', $message);
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
