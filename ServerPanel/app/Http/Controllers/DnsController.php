<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    private const NAMESERVER_TABLE = 'serverpanel_dns_nameservers';

    public function nameservers(): Response
    {
        $this->ensureDnsTables();

        return Inertia::render('DnsNameservers', [
            'nameservers' => $this->pdns()->table(self::NAMESERVER_TABLE)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'domain' => (string) $row->domain,
                    'hostname' => (string) $row->hostname,
                    'ipv4' => $row->ipv4 ? (string) $row->ipv4 : null,
                    'ipv6' => $row->ipv6 ? (string) $row->ipv6 : null,
                    'ttl' => (int) $row->ttl,
                    'status' => (string) $row->status,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])
                ->values()
                ->all(),
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    public function storeNameserver(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255'],
            'ipv4' => ['nullable', 'ip'],
            'ipv6' => ['nullable', 'ip'],
            'ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'status' => ['required', 'in:active,disabled'],
        ]);

        $this->ensureDnsTables();

        $domain = $this->normalizeDomain($validated['domain']);
        $hostname = $this->normalizeDomain($validated['hostname']);
        $zone = $this->findDomainByName($domain);
        if (! $zone) {
            return redirect()->route('dns.nameservers')->with('error', 'DNS zone must exist before creating nameserver.');
        }

        $now = now();
        $this->pdns()->table(self::NAMESERVER_TABLE)->insert([
            'id' => (string) Str::uuid(),
            'domain' => $domain,
            'hostname' => $hostname,
            'ipv4' => $validated['ipv4'] ?: null,
            'ipv6' => $validated['ipv6'] ?: null,
            'ttl' => (int) $validated['ttl'],
            'status' => $validated['status'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->syncNameserverRecordsForDomain($domain, (int) $zone->id);

        return redirect()->route('dns.nameservers')->with('success', 'Nameserver created.');
    }

    public function updateNameserver(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255'],
            'ipv4' => ['nullable', 'ip'],
            'ipv6' => ['nullable', 'ip'],
            'ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'status' => ['required', 'in:active,disabled'],
        ]);

        $this->ensureDnsTables();

        $existing = $this->pdns()->table(self::NAMESERVER_TABLE)->where('id', $id)->first();
        if (! $existing) {
            return redirect()->route('dns.nameservers')->with('error', 'Nameserver not found.');
        }

        $domain = $this->normalizeDomain($validated['domain']);
        $hostname = $this->normalizeDomain($validated['hostname']);
        $zone = $this->findDomainByName($domain);
        if (! $zone) {
            return redirect()->route('dns.nameservers')->with('error', 'DNS zone must exist before updating nameserver.');
        }

        $this->pdns()->table(self::NAMESERVER_TABLE)
            ->where('id', $id)
            ->update([
                'domain' => $domain,
                'hostname' => $hostname,
                'ipv4' => $validated['ipv4'] ?: null,
                'ipv6' => $validated['ipv6'] ?: null,
                'ttl' => (int) $validated['ttl'],
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);

        $oldDomain = (string) $existing->domain;
        $oldHostname = $this->normalizeDomain((string) $existing->hostname);
        $this->pdns()->table('records')
            ->where('domain_id', (int) $zone->id)
            ->whereIn('type', ['A', 'AAAA'])
            ->where('name', $oldHostname)
            ->delete();

        $this->pdns()->table('records')
            ->where('domain_id', (int) $zone->id)
            ->where('type', 'NS')
            ->where('name', $domain)
            ->where('content', $oldHostname)
            ->delete();

        if ($oldDomain !== $domain) {
            $oldZone = $this->findDomainByName($oldDomain);
            if ($oldZone) {
                $this->pdns()->table('records')
                    ->where('domain_id', (int) $oldZone->id)
                    ->whereIn('type', ['A', 'AAAA'])
                    ->where('name', $oldHostname)
                    ->delete();

                $this->pdns()->table('records')
                    ->where('domain_id', (int) $oldZone->id)
                    ->where('type', 'NS')
                    ->where('name', $oldDomain)
                    ->where('content', $oldHostname)
                    ->delete();

                $this->syncNameserverRecordsForDomain($oldDomain, (int) $oldZone->id);
            }
        }

        $this->syncNameserverRecordsForDomain($domain, (int) $zone->id);

        return redirect()->route('dns.nameservers')->with('success', 'Nameserver updated.');
    }

    public function destroyNameserver(string $id): RedirectResponse
    {
        $this->ensureDnsTables();

        $existing = $this->pdns()->table(self::NAMESERVER_TABLE)->where('id', $id)->first();
        if (! $existing) {
            return redirect()->route('dns.nameservers')->with('error', 'Nameserver not found.');
        }

        $domain = (string) $existing->domain;
        $hostname = $this->normalizeDomain((string) $existing->hostname);
        $this->pdns()->table(self::NAMESERVER_TABLE)->where('id', $id)->delete();

        $zone = $this->findDomainByName($domain);
        if ($zone) {
            $this->pdns()->table('records')
                ->where('domain_id', (int) $zone->id)
                ->whereIn('type', ['A', 'AAAA'])
                ->where('name', $hostname)
                ->delete();

            $this->pdns()->table('records')
                ->where('domain_id', (int) $zone->id)
                ->where('type', 'NS')
                ->where('name', $domain)
                ->where('content', $hostname)
                ->delete();

            $this->syncNameserverRecordsForDomain($domain, (int) $zone->id);
        }

        return redirect()->route('dns.nameservers')->with('success', 'Nameserver deleted.');
    }

    public function zones(): Response
    {
        $this->ensureDnsTables();
        $domains = $this->pdns()->table('domains')->orderByDesc('id')->get();
        $soaRecords = $this->pdns()->table('records')
            ->where('type', 'SOA')
            ->whereIn('domain_id', $domains->pluck('id')->all())
            ->get()
            ->keyBy('domain_id');

        $zones = $domains->map(function ($domainRow) use ($soaRecords) {
            $soa = $soaRecords->get($domainRow->id);
            $soaParsed = $this->parseSoaContent((string) ($soa->content ?? ''));

            return [
                'id' => (string) $domainRow->id,
                'domain' => (string) $domainRow->name,
                'type' => $this->pdnsZoneTypeToUi((string) ($domainRow->type ?? 'NATIVE')),
                'email' => $soaParsed['email'],
                'refresh' => $soaParsed['refresh'],
                'retry' => $soaParsed['retry'],
                'expire' => $soaParsed['expire'],
                'minimum_ttl' => $soaParsed['minimum_ttl'],
                'status' => ((int) ($soa->disabled ?? 0)) === 1 ? 'disabled' : 'active',
                'created_at' => $domainRow->id,
            ];
        })->values()->all();

        return Inertia::render('DnsZones', [
            'zones' => $zones,
            'websiteDomains' => $this->readWebsiteDomains(),
            'cloudflareGuide' => $this->buildCloudflareGuide(),
        ]);
    }

    public function storeZone(Request $request): RedirectResponse
    {
        $this->ensureDnsTables();
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:master,slave'],
            'email' => ['required', 'email', 'max:255'],
            'refresh' => ['required', 'integer', 'min:300', 'max:86400'],
            'retry' => ['required', 'integer', 'min:60', 'max:86400'],
            'expire' => ['required', 'integer', 'min:3600', 'max:2592000'],
            'minimum_ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'status' => ['required', 'in:active,disabled'],
        ]);

        $domain = $this->normalizeDomain($validated['domain']);
        if ($this->findDomainByName($domain)) {
            return redirect()->route('dns.zones')->with('error', 'Zone already exists.');
        }

        $zoneType = $this->uiZoneTypeToPdns($validated['type']);
        $zoneId = (int) $this->pdns()->table('domains')->insertGetId([
            'name' => $domain,
            'type' => $zoneType,
        ]);

        $this->upsertSoaRecord($zoneId, $domain, $validated);
        $this->syncNameserverRecordsForDomain($domain, $zoneId);

        return redirect()->route('dns.zones')->with('success', 'DNS zone created.');
    }

    public function updateZone(Request $request, string $id): RedirectResponse
    {
        $this->ensureDnsTables();
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:master,slave'],
            'email' => ['required', 'email', 'max:255'],
            'refresh' => ['required', 'integer', 'min:300', 'max:86400'],
            'retry' => ['required', 'integer', 'min:60', 'max:86400'],
            'expire' => ['required', 'integer', 'min:3600', 'max:2592000'],
            'minimum_ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'status' => ['required', 'in:active,disabled'],
        ]);

        $zoneId = (int) $id;
        $existingZone = $this->pdns()->table('domains')->where('id', $zoneId)->first();
        if (! $existingZone) {
            return redirect()->route('dns.zones')->with('error', 'DNS zone not found.');
        }

        $oldDomain = (string) $existingZone->name;
        $newDomain = $this->normalizeDomain($validated['domain']);

        if ($oldDomain !== $newDomain && $this->findDomainByName($newDomain)) {
            return redirect()->route('dns.zones')->with('error', 'Domain already exists.');
        }

        $this->pdns()->transaction(function () use ($zoneId, $validated, $oldDomain, $newDomain) {
            $this->pdns()->table('domains')->where('id', $zoneId)->update([
                'name' => $newDomain,
                'type' => $this->uiZoneTypeToPdns($validated['type']),
            ]);

            if ($oldDomain !== $newDomain) {
                $records = $this->pdns()->table('records')->where('domain_id', $zoneId)->get();
                foreach ($records as $record) {
                    $currentName = (string) $record->name;
                    $newName = $this->replaceDomainSuffix($currentName, $oldDomain, $newDomain);
                    if ($newName !== $currentName) {
                        $this->pdns()->table('records')->where('id', $record->id)->update(['name' => $newName]);
                    }
                }

                $this->ensureNameserverTable();
                $this->pdns()->table(self::NAMESERVER_TABLE)->where('domain', $oldDomain)->update(['domain' => $newDomain, 'updated_at' => now()]);
            }

            $this->upsertSoaRecord($zoneId, $newDomain, $validated);
            $this->syncNameserverRecordsForDomain($newDomain, $zoneId);
        });

        return redirect()->route('dns.zones')->with('success', 'DNS zone updated.');
    }

    public function destroyZone(string $id): RedirectResponse
    {
        $this->ensureDnsTables();
        $zoneId = (int) $id;
        $zone = $this->pdns()->table('domains')->where('id', $zoneId)->first();
        if (! $zone) {
            return redirect()->route('dns.zones')->with('error', 'DNS zone not found.');
        }

        $zoneDomain = (string) $zone->name;
        $this->pdns()->transaction(function () use ($zoneId, $zoneDomain) {
            $this->pdns()->table('records')->where('domain_id', $zoneId)->delete();
            $this->pdns()->table('domains')->where('id', $zoneId)->delete();
            $this->ensureNameserverTable();
            $this->pdns()->table(self::NAMESERVER_TABLE)->where('domain', $zoneDomain)->delete();
        });

        return redirect()->route('dns.zones')->with('success', 'DNS zone deleted.');
    }

    public function records(): Response
    {
        $this->ensureDnsTables();
        $records = $this->pdns()->table('records as r')
            ->join('domains as d', 'd.id', '=', 'r.domain_id')
            ->where('r.type', '!=', 'SOA')
            ->orderByDesc('r.id')
            ->select(['r.id', 'r.name', 'r.type', 'r.content', 'r.ttl', 'r.prio', 'r.disabled', 'd.name as zone_domain'])
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'zone_domain' => (string) $row->zone_domain,
                'type' => (string) $row->type,
                'name' => $this->toUiRecordName((string) $row->name, (string) $row->zone_domain),
                'content' => (string) $row->content,
                'ttl' => (int) $row->ttl,
                'priority' => $row->prio !== null ? (int) $row->prio : null,
                'status' => ((int) $row->disabled) === 1 ? 'disabled' : 'active',
                'created_at' => $row->id,
            ])
            ->values()
            ->all();

        $zones = $this->pdns()->table('domains')->orderBy('name')->pluck('name')->values()->all();

        return Inertia::render('DnsRecords', [
            'records' => $records,
            'zoneDomains' => $zones,
        ]);
    }

    public function storeRecord(Request $request): RedirectResponse
    {
        $this->ensureDnsTables();
        $validated = $request->validate([
            'zone_domain' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:A,AAAA,CNAME,MX,TXT,NS,SRV'],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:2048'],
            'ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', 'in:active,disabled'],
        ]);

        $zoneDomain = $this->normalizeDomain($validated['zone_domain']);
        $zone = $this->findDomainByName($zoneDomain);
        if (! $zone) {
            return redirect()->route('dns.records')->with('error', 'Zone not found.');
        }

        $name = $this->toFqdnRecordName($validated['name'], $zoneDomain);
        $this->pdns()->table('records')->insert([
            'domain_id' => (int) $zone->id,
            'name' => $name,
            'type' => strtoupper((string) $validated['type']),
            'content' => (string) $validated['content'],
            'ttl' => (int) $validated['ttl'],
            'prio' => $validated['priority'] !== null ? (int) $validated['priority'] : 0,
            'disabled' => $validated['status'] === 'disabled' ? 1 : 0,
            'auth' => 1,
        ]);

        return redirect()->route('dns.records')->with('success', 'DNS record created.');
    }

    public function updateRecord(Request $request, string $id): RedirectResponse
    {
        $this->ensureDnsTables();
        $validated = $request->validate([
            'zone_domain' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:A,AAAA,CNAME,MX,TXT,NS,SRV'],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:2048'],
            'ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', 'in:active,disabled'],
        ]);

        $recordId = (int) $id;
        $record = $this->pdns()->table('records')->where('id', $recordId)->first();
        if (! $record) {
            return redirect()->route('dns.records')->with('error', 'DNS record not found.');
        }

        $zoneDomain = $this->normalizeDomain($validated['zone_domain']);
        $zone = $this->findDomainByName($zoneDomain);
        if (! $zone) {
            return redirect()->route('dns.records')->with('error', 'Zone not found.');
        }

        $name = $this->toFqdnRecordName($validated['name'], $zoneDomain);
        $this->pdns()->table('records')->where('id', $recordId)->update([
            'domain_id' => (int) $zone->id,
            'name' => $name,
            'type' => strtoupper((string) $validated['type']),
            'content' => (string) $validated['content'],
            'ttl' => (int) $validated['ttl'],
            'prio' => $validated['priority'] !== null ? (int) $validated['priority'] : 0,
            'disabled' => $validated['status'] === 'disabled' ? 1 : 0,
            'auth' => 1,
        ]);

        return redirect()->route('dns.records')->with('success', 'DNS record updated.');
    }

    public function destroyRecord(string $id): RedirectResponse
    {
        $this->ensureDnsTables();
        $this->pdns()->table('records')->where('id', (int) $id)->delete();

        return redirect()->route('dns.records')->with('success', 'DNS record deleted.');
    }

    public function syncCloudflare(Request $request): RedirectResponse
    {
        $this->ensureDnsTables();
        $validated = $request->validate([
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $token = trim((string) env('CLOUDFLARE_API_TOKEN', ''));
        if ($token === '') {
            return redirect()->route('dns.zones')->with('error', 'Cloudflare API token is missing. Set CLOUDFLARE_API_TOKEN in .env.');
        }

        $domainFilter = isset($validated['domain']) && $validated['domain'] !== ''
            ? $this->normalizeDomain((string) $validated['domain'])
            : null;

        $zonesQuery = $this->pdns()->table('domains');
        if ($domainFilter !== null) {
            $zonesQuery->where('name', $domainFilter);
        }

        $zones = $zonesQuery->get(['id', 'name']);
        if ($zones->isEmpty()) {
            return redirect()->route('dns.zones')->with('error', 'No DNS zone found to sync.');
        }

        $zoneMap = $this->cloudflareZoneMap();
        $proxied = filter_var(env('CLOUDFLARE_SYNC_PROXIED', false), FILTER_VALIDATE_BOOLEAN);
        $summary = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];

        foreach ($zones as $zone) {
            $zoneDomain = $this->normalizeDomain((string) $zone->name);
            $zoneId = (int) $zone->id;

            try {
                $cloudflareZoneId = $this->resolveCloudflareZoneId($token, $zoneDomain, $zoneMap);
                if ($cloudflareZoneId === null) {
                    $summary['failed']++;
                    $errors[] = "{$zoneDomain}: Cloudflare zone not found.";
                    continue;
                }

                $existingRecords = $this->fetchCloudflareRecords($token, $cloudflareZoneId);
                $existingFingerprints = collect($existingRecords)
                    ->map(fn (array $record) => $this->cloudflareRecordFingerprint($record))
                    ->filter()
                    ->flip();

                $records = $this->pdns()->table('records')
                    ->where('domain_id', $zoneId)
                    ->where('type', '!=', 'SOA')
                    ->where('disabled', 0)
                    ->get(['name', 'type', 'content', 'ttl', 'prio']);

                foreach ($records as $record) {
                    $payload = $this->toCloudflarePayload($record, $zoneDomain, $proxied);
                    if ($payload === null) {
                        $summary['skipped']++;
                        continue;
                    }

                    $fingerprint = $this->cloudflareRecordFingerprint($payload);
                    if ($fingerprint === null) {
                        $summary['skipped']++;
                        continue;
                    }

                    if ($existingFingerprints->has($fingerprint)) {
                        $summary['skipped']++;
                        continue;
                    }

                    $response = $this->cloudflareRequest(
                        $token,
                        'POST',
                        "zones/{$cloudflareZoneId}/dns_records",
                        $payload
                    );

                    if (! (bool) ($response['success'] ?? false)) {
                        $summary['failed']++;
                        $errors[] = "{$zoneDomain}: ".((string) data_get($response, 'errors.0.message', 'Create failed.'));
                        continue;
                    }

                    $summary['created']++;
                    $existingFingerprints->put($fingerprint, true);
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $errors[] = "{$zoneDomain}: ".$exception->getMessage();
            }
        }

        if ($summary['failed'] > 0) {
            $message = "Cloudflare sync completed with errors. Created {$summary['created']}, skipped {$summary['skipped']}, failed {$summary['failed']}.";
            if (! empty($errors)) {
                $message .= ' '.implode(' | ', array_slice($errors, 0, 3));
            }

            return redirect()->route('dns.zones')->with('error', $message);
        }

        return redirect()->route('dns.zones')->with(
            'success',
            "Cloudflare sync completed. Created {$summary['created']}, skipped {$summary['skipped']}."
        );
    }

    private function pdns()
    {
        $connection = (string) config('database.default', env('DB_CONNECTION', 'mysql'));

        return DB::connection($connection);
    }

    private function ensureDnsTables(): void
    {
        $connection = (string) config('database.default', env('DB_CONNECTION', 'mysql'));
        $schema = Schema::connection($connection);

        if (! $schema->hasTable('domains')) {
            $schema->create('domains', function ($table): void {
                $table->bigIncrements('id');
                $table->string('name', 255)->unique();
                $table->string('master', 128)->nullable();
                $table->string('last_check', 32)->nullable();
                $table->string('type', 16)->default('NATIVE');
                $table->unsignedBigInteger('notified_serial')->nullable();
                $table->string('account', 40)->nullable();
            });
        }

        if (! $schema->hasTable('records')) {
            $schema->create('records', function ($table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('domain_id')->nullable()->index();
                $table->string('name', 255)->nullable()->index();
                $table->string('type', 16)->nullable()->index();
                $table->text('content')->nullable();
                $table->unsignedInteger('ttl')->nullable();
                $table->unsignedInteger('prio')->nullable();
                $table->unsignedTinyInteger('disabled')->default(0);
                $table->string('ordername', 255)->nullable();
                $table->unsignedTinyInteger('auth')->default(1);
            });
        }

        if (! $schema->hasTable(self::NAMESERVER_TABLE)) {
            $schema->create(self::NAMESERVER_TABLE, function ($table): void {
                $table->uuid('id')->primary();
                $table->string('domain', 255)->index();
                $table->string('hostname', 255)->index();
                $table->string('ipv4', 45)->nullable();
                $table->string('ipv6', 45)->nullable();
                $table->unsignedInteger('ttl')->default(3600);
                $table->string('status', 16)->default('active');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    private function ensureNameserverTable(): void
    {
        $this->ensureDnsTables();
    }

    private function syncNameserverRecordsForDomain(string $domain, int $zoneId): void
    {
        $this->ensureNameserverTable();

        $allEntries = $this->pdns()->table(self::NAMESERVER_TABLE)
            ->where('domain', $domain)
            ->get(['hostname']);

        $hostnames = $allEntries
            ->pluck('hostname')
            ->map(fn ($value) => $this->normalizeDomain((string) $value))
            ->filter()
            ->unique()
            ->values();

        $this->pdns()->table('records')
            ->where('domain_id', $zoneId)
            ->where('type', 'NS')
            ->where('name', $domain)
            ->delete();

        if ($hostnames->isNotEmpty()) {
            $this->pdns()->table('records')
                ->where('domain_id', $zoneId)
                ->whereIn('type', ['A', 'AAAA'])
                ->whereIn('name', $hostnames->all())
                ->delete();
        } else {
            $this->pdns()->table('records')
                ->where('domain_id', $zoneId)
                ->whereIn('type', ['A', 'AAAA'])
                ->where('name', 'like', "ns%.{$domain}")
                ->delete();
        }

        $activeEntries = $this->pdns()->table(self::NAMESERVER_TABLE)
            ->where('domain', $domain)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get();

        foreach ($activeEntries as $entry) {
            $ttl = max(60, (int) $entry->ttl);
            $hostname = $this->normalizeDomain((string) $entry->hostname);

            $this->pdns()->table('records')->insert([
                'domain_id' => $zoneId,
                'name' => $domain,
                'type' => 'NS',
                'content' => $hostname,
                'ttl' => $ttl,
                'prio' => 0,
                'disabled' => 0,
                'auth' => 1,
            ]);

            if (! empty($entry->ipv4)) {
                $this->pdns()->table('records')->insert([
                    'domain_id' => $zoneId,
                    'name' => $hostname,
                    'type' => 'A',
                    'content' => (string) $entry->ipv4,
                    'ttl' => $ttl,
                    'prio' => 0,
                    'disabled' => 0,
                    'auth' => 1,
                ]);
            }

            if (! empty($entry->ipv6)) {
                $this->pdns()->table('records')->insert([
                    'domain_id' => $zoneId,
                    'name' => $hostname,
                    'type' => 'AAAA',
                    'content' => (string) $entry->ipv6,
                    'ttl' => $ttl,
                    'prio' => 0,
                    'disabled' => 0,
                    'auth' => 1,
                ]);
            }
        }
    }

    private function upsertSoaRecord(int $zoneId, string $domain, array $validated): void
    {
        $this->ensureNameserverTable();

        $primaryNs = $this->pdns()->table(self::NAMESERVER_TABLE)
            ->where('domain', $domain)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->value('hostname');

        $primaryNs = $primaryNs ? $this->normalizeDomain((string) $primaryNs) : "ns1.{$domain}";
        $serial = now()->format('Ymd').'01';
        $soaContent = implode(' ', [
            $primaryNs,
            $this->emailToSoaMailbox((string) $validated['email']),
            $serial,
            (int) $validated['refresh'],
            (int) $validated['retry'],
            (int) $validated['expire'],
            (int) $validated['minimum_ttl'],
        ]);

        $existing = $this->pdns()->table('records')
            ->where('domain_id', $zoneId)
            ->where('type', 'SOA')
            ->first();

        if ($existing) {
            $this->pdns()->table('records')->where('id', $existing->id)->update([
                'name' => $domain,
                'content' => $soaContent,
                'ttl' => (int) $validated['minimum_ttl'],
                'prio' => 0,
                'disabled' => $validated['status'] === 'disabled' ? 1 : 0,
                'auth' => 1,
            ]);
            return;
        }

        $this->pdns()->table('records')->insert([
            'domain_id' => $zoneId,
            'name' => $domain,
            'type' => 'SOA',
            'content' => $soaContent,
            'ttl' => (int) $validated['minimum_ttl'],
            'prio' => 0,
            'disabled' => $validated['status'] === 'disabled' ? 1 : 0,
            'auth' => 1,
        ]);
    }

    private function findDomainByName(string $domain): ?object
    {
        return $this->pdns()->table('domains')->where('name', $domain)->first();
    }

    private function normalizeDomain(string $domain): string
    {
        return strtolower(trim(rtrim($domain, '.')));
    }

    private function toFqdnRecordName(string $name, string $zoneDomain): string
    {
        $normalized = strtolower(trim($name));
        if ($normalized === '' || $normalized === '@') {
            return $zoneDomain;
        }

        $normalized = rtrim($normalized, '.');
        if (Str::endsWith($normalized, ".{$zoneDomain}") || $normalized === $zoneDomain) {
            return $normalized;
        }

        return "{$normalized}.{$zoneDomain}";
    }

    private function toUiRecordName(string $fqdn, string $zoneDomain): string
    {
        $fqdn = $this->normalizeDomain($fqdn);
        if ($fqdn === $zoneDomain) {
            return '@';
        }

        $suffix = ".{$zoneDomain}";
        if (Str::endsWith($fqdn, $suffix)) {
            return (string) Str::beforeLast($fqdn, $suffix);
        }

        return $fqdn;
    }

    private function replaceDomainSuffix(string $name, string $oldDomain, string $newDomain): string
    {
        $name = $this->normalizeDomain($name);
        if ($name === $oldDomain) {
            return $newDomain;
        }

        $oldSuffix = ".{$oldDomain}";
        if (Str::endsWith($name, $oldSuffix)) {
            return Str::replaceLast($oldSuffix, ".{$newDomain}", $name);
        }

        return $name;
    }

    private function emailToSoaMailbox(string $email): string
    {
        $normalized = strtolower(trim($email));
        $parts = explode('@', $normalized, 2);
        if (count($parts) !== 2) {
            return "hostmaster.{$normalized}";
        }

        return "{$parts[0]}.{$parts[1]}";
    }

    /**
     * @return array{email:string,refresh:int,retry:int,expire:int,minimum_ttl:int}
     */
    private function parseSoaContent(string $content): array
    {
        $parts = preg_split('/\s+/', trim($content)) ?: [];
        $mailbox = (string) ($parts[1] ?? 'hostmaster.localhost');
        $mailboxParts = explode('.', $mailbox, 2);
        $email = count($mailboxParts) === 2 ? "{$mailboxParts[0]}@{$mailboxParts[1]}" : $mailbox;

        return [
            'email' => $email,
            'refresh' => isset($parts[3]) ? (int) $parts[3] : 3600,
            'retry' => isset($parts[4]) ? (int) $parts[4] : 600,
            'expire' => isset($parts[5]) ? (int) $parts[5] : 1209600,
            'minimum_ttl' => isset($parts[6]) ? (int) $parts[6] : 3600,
        ];
    }

    private function uiZoneTypeToPdns(string $type): string
    {
        return strtolower($type) === 'slave' ? 'SLAVE' : 'NATIVE';
    }

    private function pdnsZoneTypeToUi(string $type): string
    {
        return strtoupper($type) === 'SLAVE' ? 'slave' : 'master';
    }

    /**
     * @return array<int, string>
     */
    private function readWebsiteDomains(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return [];
            }

            return Website::query()
                ->pluck('domain')
                ->filter(fn ($domain) => is_string($domain) && trim((string) $domain) !== '')
                ->map(fn ($domain) => strtolower(trim((string) $domain)))
                ->unique()
                ->sort()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function readMailboxDomains(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('mailboxes')) {
                return [];
            }

            return Mailbox::query()
                ->pluck('domain')
                ->filter(fn ($domain) => is_string($domain) && trim((string) $domain) !== '')
                ->map(fn ($domain) => strtolower(trim((string) $domain)))
                ->unique()
                ->sort()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCloudflareGuide(): array
    {
        $mailDomains = $this->readMailboxDomains();
        $primaryDomain = (string) ($mailDomains[0] ?? '');
        if ($primaryDomain === '') {
            $websiteDomains = $this->readWebsiteDomains();
            $primaryDomain = (string) ($websiteDomains[0] ?? 'example.com');
        }

        $mailHost = 'mail.'.$primaryDomain;
        $selector = trim((string) config('serverpanel.mail.dkim_selector', 'default'));
        if ($selector === '') {
            $selector = 'default';
        }
        $apiTokenReady = trim((string) env('CLOUDFLARE_API_TOKEN', '')) !== '';
        $zoneMapReady = $this->cloudflareZoneMap() !== [];
        $syncProxied = filter_var(env('CLOUDFLARE_SYNC_PROXIED', false), FILTER_VALIDATE_BOOLEAN);

        return [
            'api_token_ready' => $apiTokenReady,
            'zone_map_ready' => $zoneMapReady,
            'sync_proxied' => $syncProxied,
            'mail_domains' => $mailDomains,
            'primary_domain' => $primaryDomain,
            'records' => [
                [
                    'type' => 'A',
                    'name' => 'mail',
                    'content' => 'YOUR_SERVER_IP',
                    'note' => 'DNS only. Do not proxy mail hostnames.',
                ],
                [
                    'type' => 'MX',
                    'name' => '@',
                    'content' => '10 '.$mailHost,
                    'note' => 'DNS only. Point MX to the mail hostname.',
                ],
                [
                    'type' => 'TXT',
                    'name' => '@',
                    'content' => 'v=spf1 mx a ~all',
                    'note' => 'SPF for outbound mail.',
                ],
                [
                    'type' => 'TXT',
                    'name' => $selector.'._domainkey',
                    'content' => 'v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY',
                    'note' => 'Replace with your DKIM public key.',
                ],
                [
                    'type' => 'TXT',
                    'name' => '_dmarc',
                    'content' => 'v=DMARC1; p=none; rua=mailto:postmaster@'.$primaryDomain,
                    'note' => 'Start with monitoring mode.',
                ],
            ],
            'notes' => [
                'Cloudflare proxy must stay off for MX, mail, IMAP, POP3, SMTP and DKIM TXT records.',
                'Use the Sync To Cloudflare action for web records only, then verify mail records remain DNS only.',
                'If your mail service uses a different hostname, update the MX and DKIM values to match that hostname.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function cloudflareZoneMap(): array
    {
        $raw = trim((string) env('CLOUDFLARE_ZONE_MAP', ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $domain => $zoneId) {
            if (! is_string($domain) || ! is_string($zoneId)) {
                continue;
            }

            $normalizedDomain = $this->normalizeDomain($domain);
            $trimmedZoneId = trim($zoneId);
            if ($normalizedDomain === '' || $trimmedZoneId === '') {
                continue;
            }

            $map[$normalizedDomain] = $trimmedZoneId;
        }

        return $map;
    }

    private function resolveCloudflareZoneId(string $token, string $domain, array $zoneMap): ?string
    {
        if (isset($zoneMap[$domain])) {
            return $zoneMap[$domain];
        }

        $response = $this->cloudflareRequest($token, 'GET', 'zones?name='.urlencode($domain).'&per_page=1');
        if (! (bool) ($response['success'] ?? false)) {
            return null;
        }

        $zoneId = data_get($response, 'result.0.id');

        return is_string($zoneId) && $zoneId !== '' ? $zoneId : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCloudflareRecords(string $token, string $cloudflareZoneId): array
    {
        $page = 1;
        $all = [];

        do {
            $response = $this->cloudflareRequest(
                $token,
                'GET',
                "zones/{$cloudflareZoneId}/dns_records?per_page=500&page={$page}"
            );

            if (! (bool) ($response['success'] ?? false)) {
                throw new \RuntimeException((string) data_get($response, 'errors.0.message', 'Failed to fetch existing Cloudflare DNS records.'));
            }

            $result = data_get($response, 'result', []);
            if (is_array($result)) {
                foreach ($result as $record) {
                    if (is_array($record)) {
                        $all[] = $record;
                    }
                }
            }

            $totalPages = (int) data_get($response, 'result_info.total_pages', 1);
            $page++;
        } while ($page <= max(1, $totalPages));

        return $all;
    }

    /**
     * @param  object  $record
     * @return array<string, mixed>|null
     */
    private function toCloudflarePayload(object $record, string $zoneDomain, bool $proxied): ?array
    {
        $type = strtoupper((string) ($record->type ?? ''));
        $supportedTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV'];
        if (! in_array($type, $supportedTypes, true)) {
            return null;
        }

        $name = $this->normalizeDomain((string) ($record->name ?? ''));
        if ($name === '') {
            return null;
        }

        $ttl = max(60, min(86400, (int) ($record->ttl ?? 3600)));
        $payload = ['type' => $type, 'name' => $name, 'ttl' => $ttl];

        if ($type === 'SRV') {
            $srvData = $this->toCloudflareSrvData(
                $name,
                $zoneDomain,
                (string) ($record->content ?? ''),
                (int) ($record->prio ?? 0)
            );
            if ($srvData === null) {
                return null;
            }

            $payload['data'] = $srvData;

            return $payload;
        }

        $content = trim((string) ($record->content ?? ''));
        if ($content === '') {
            return null;
        }

        if (in_array($type, ['CNAME', 'MX', 'NS'], true)) {
            $content = rtrim($content, '.');
        }

        $payload['content'] = $content;

        if ($type === 'MX') {
            $payload['priority'] = max(0, min(65535, (int) ($record->prio ?? 0)));
        }

        if ($proxied && in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = true;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toCloudflareSrvData(string $name, string $zoneDomain, string $content, int $prio): ?array
    {
        $service = '';
        $proto = '';
        $host = '@';

        if (preg_match('/^(_[a-z0-9-]+)\.(_(?:tcp|udp))\.(.+)$/i', $name, $matches) === 1) {
            $service = strtolower($matches[1]);
            $proto = strtolower($matches[2]);
            $host = $this->toUiRecordName($matches[3], $zoneDomain);
        } elseif (preg_match('/^(_[a-z0-9-]+)\.(_(?:tcp|udp))$/i', $name, $matches) === 1) {
            $service = strtolower($matches[1]);
            $proto = strtolower($matches[2]);
            $host = '@';
        } else {
            return null;
        }

        $parts = preg_split('/\s+/', trim($content)) ?: [];
        $priority = $prio;
        $weight = 0;
        $port = 0;
        $target = '';

        if (count($parts) === 4) {
            $priority = (int) $parts[0];
            $weight = (int) $parts[1];
            $port = (int) $parts[2];
            $target = (string) $parts[3];
        } elseif (count($parts) === 3) {
            $weight = (int) $parts[0];
            $port = (int) $parts[1];
            $target = (string) $parts[2];
        } else {
            return null;
        }

        $target = rtrim($target, '.');
        if ($target === '') {
            return null;
        }

        return [
            'service' => $service,
            'proto' => $proto,
            'name' => $host,
            'priority' => max(0, min(65535, $priority)),
            'weight' => max(0, min(65535, $weight)),
            'port' => max(0, min(65535, $port)),
            'target' => $target,
        ];
    }

    private function cloudflareRecordFingerprint(array $record): ?string
    {
        $type = strtoupper((string) data_get($record, 'type', ''));
        $name = $this->normalizeDomain((string) data_get($record, 'name', ''));
        if ($type === '' || $name === '') {
            return null;
        }

        if ($type === 'SRV') {
            $service = strtolower((string) data_get($record, 'data.service', ''));
            $proto = strtolower((string) data_get($record, 'data.proto', ''));
            $srvName = strtolower((string) data_get($record, 'data.name', '@'));
            $priority = (int) data_get($record, 'data.priority', 0);
            $weight = (int) data_get($record, 'data.weight', 0);
            $port = (int) data_get($record, 'data.port', 0);
            $target = $this->normalizeDomain((string) data_get($record, 'data.target', ''));
            if ($service === '' || $proto === '' || $target === '') {
                return null;
            }

            return implode('|', [$type, $name, $service, $proto, $srvName, $priority, $weight, $port, $target]);
        }

        $content = (string) data_get($record, 'content', '');
        if (in_array($type, ['CNAME', 'MX', 'NS'], true)) {
            $content = $this->normalizeDomain($content);
        }

        if ($content === '') {
            return null;
        }

        $priority = $type === 'MX' ? (int) data_get($record, 'priority', 0) : 0;

        return implode('|', [$type, $name, trim($content), $priority]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cloudflareRequest(string $token, string $method, string $uri, array $payload = []): array
    {
        $url = 'https://api.cloudflare.com/client/v4/'.ltrim($uri, '/');
        $request = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        $response = $method === 'GET'
            ? $request->get($url)
            : $request->send($method, $url, ['json' => $payload]);

        $decoded = $response->json();
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['success' => false, 'errors' => [['message' => 'Invalid Cloudflare response.']]];
    }
}
