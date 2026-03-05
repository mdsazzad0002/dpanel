<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DnsController extends Controller
{
    private const STORAGE_FILE = 'dns-management.json';
    private const WEBSITE_STORAGE_FILE = 'website-requests.json';

    public function nameservers(): Response
    {
        $state = $this->readState();

        return Inertia::render('DnsNameservers', [
            'nameservers' => collect($state['nameservers'])->sortByDesc('created_at')->values()->all(),
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

        $state = $this->readState();
        $state['nameservers'][] = [
            'id' => (string) str()->uuid(),
            ...$validated,
            'created_at' => now()->toIso8601String(),
        ];
        $this->writeState($state);

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

        $state = $this->readState();
        $state['nameservers'] = collect($state['nameservers'])->map(function (array $item) use ($id, $validated) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            return [
                ...$item,
                ...$validated,
                'updated_at' => now()->toIso8601String(),
            ];
        })->values()->all();
        $this->writeState($state);

        return redirect()->route('dns.nameservers')->with('success', 'Nameserver updated.');
    }

    public function destroyNameserver(string $id): RedirectResponse
    {
        $state = $this->readState();
        $state['nameservers'] = collect($state['nameservers'])
            ->reject(fn (array $item) => ($item['id'] ?? null) === $id)
            ->values()
            ->all();
        $this->writeState($state);

        return redirect()->route('dns.nameservers')->with('success', 'Nameserver deleted.');
    }

    public function zones(): Response
    {
        $state = $this->readState();

        return Inertia::render('DnsZones', [
            'zones' => collect($state['zones'])->sortByDesc('created_at')->values()->all(),
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    public function storeZone(Request $request): RedirectResponse
    {
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

        $state = $this->readState();
        $state['zones'][] = [
            'id' => (string) str()->uuid(),
            ...$validated,
            'created_at' => now()->toIso8601String(),
        ];
        $this->writeState($state);

        return redirect()->route('dns.zones')->with('success', 'DNS zone created.');
    }

    public function updateZone(Request $request, string $id): RedirectResponse
    {
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

        $state = $this->readState();
        $state['zones'] = collect($state['zones'])->map(function (array $item) use ($id, $validated) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            return [
                ...$item,
                ...$validated,
                'updated_at' => now()->toIso8601String(),
            ];
        })->values()->all();
        $this->writeState($state);

        return redirect()->route('dns.zones')->with('success', 'DNS zone updated.');
    }

    public function destroyZone(string $id): RedirectResponse
    {
        $state = $this->readState();
        $zone = collect($state['zones'])->firstWhere('id', $id);
        $zoneDomain = (string) ($zone['domain'] ?? '');

        $state['zones'] = collect($state['zones'])
            ->reject(fn (array $item) => ($item['id'] ?? null) === $id)
            ->values()
            ->all();

        if ($zoneDomain !== '') {
            $state['records'] = collect($state['records'])
                ->reject(fn (array $item) => strtolower((string) ($item['zone_domain'] ?? '')) === strtolower($zoneDomain))
                ->values()
                ->all();
        }

        $this->writeState($state);

        return redirect()->route('dns.zones')->with('success', 'DNS zone deleted.');
    }

    public function records(): Response
    {
        $state = $this->readState();

        return Inertia::render('DnsRecords', [
            'records' => collect($state['records'])->sortByDesc('created_at')->values()->all(),
            'zoneDomains' => collect($state['zones'])->pluck('domain')->unique()->values()->all(),
        ]);
    }

    public function storeRecord(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'zone_domain' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:A,AAAA,CNAME,MX,TXT,NS,SRV'],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:2048'],
            'ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', 'in:active,disabled'],
        ]);

        $state = $this->readState();
        $state['records'][] = [
            'id' => (string) str()->uuid(),
            ...$validated,
            'created_at' => now()->toIso8601String(),
        ];
        $this->writeState($state);

        return redirect()->route('dns.records')->with('success', 'DNS record created.');
    }

    public function updateRecord(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate([
            'zone_domain' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:A,AAAA,CNAME,MX,TXT,NS,SRV'],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:2048'],
            'ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'status' => ['required', 'in:active,disabled'],
        ]);

        $state = $this->readState();
        $state['records'] = collect($state['records'])->map(function (array $item) use ($id, $validated) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            return [
                ...$item,
                ...$validated,
                'updated_at' => now()->toIso8601String(),
            ];
        })->values()->all();
        $this->writeState($state);

        return redirect()->route('dns.records')->with('success', 'DNS record updated.');
    }

    public function destroyRecord(string $id): RedirectResponse
    {
        $state = $this->readState();
        $state['records'] = collect($state['records'])
            ->reject(fn (array $item) => ($item['id'] ?? null) === $id)
            ->values()
            ->all();
        $this->writeState($state);

        return redirect()->route('dns.records')->with('success', 'DNS record deleted.');
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function readState(): array
    {
        $default = [
            'nameservers' => [],
            'zones' => [],
            'records' => [],
        ];

        if (! Storage::exists(self::STORAGE_FILE)) {
            return $default;
        }

        $decoded = json_decode((string) Storage::get(self::STORAGE_FILE), true);
        if (! is_array($decoded)) {
            return $default;
        }

        return [
            'nameservers' => is_array($decoded['nameservers'] ?? null) ? $decoded['nameservers'] : [],
            'zones' => is_array($decoded['zones'] ?? null) ? $decoded['zones'] : [],
            'records' => is_array($decoded['records'] ?? null) ? $decoded['records'] : [],
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $state
     */
    private function writeState(array $state): void
    {
        Storage::put(self::STORAGE_FILE, json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<int, string>
     */
    private function readWebsiteDomains(): array
    {
        if (! Storage::exists(self::WEBSITE_STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::WEBSITE_STORAGE_FILE), true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->pluck('domain')
            ->filter(fn ($domain) => is_string($domain) && $domain !== '')
            ->unique()
            ->values()
            ->all();
    }
}
