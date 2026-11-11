<?php

namespace App\Http\Controllers;

use App\Services\Dns\Backend\DnsBackend;
use App\Services\Dns\DnsSecondaryServers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backlog M7: DNS → Zone Transfers (admin only). The secondary nameservers
 * listed here may transfer every zone this server is primary for and are
 * notified when one changes. The zones this server copies from elsewhere
 * are listed too.
 */
class DnsTransfersController extends Controller
{
    public function __construct(
        private readonly DnsSecondaryServers $secondaries,
        private readonly DnsBackend $backend,
    ) {}

    public function index(Request $request): Response
    {
        $this->adminOnly($request);
        $zones = collect();
        try {
            $zones = DB::table('domains')->orderBy('name')->get(['name', 'type', 'master', 'notified_serial', 'last_check']);
        } catch (\Throwable) {
            // No PowerDNS tables in this database.
        }

        return Inertia::render('Dns/DnsTransfers', [
            'secondaries' => $this->secondaries->all(),
            'maxSecondaries' => DnsSecondaryServers::MAX,
            'primaryZones' => $zones->filter(fn ($zone) => strtoupper((string) $zone->type) !== 'SLAVE')->count(),
            'secondaryZones' => $zones->filter(fn ($zone) => strtoupper((string) $zone->type) === 'SLAVE')->map(fn ($zone) => [
                'name' => (string) $zone->name,
                'primaries' => (string) ($zone->master ?? ''),
                // PowerDNS fills last_check once it has asked the primary.
                'last_check' => $zone->last_check ? date(DATE_ATOM, (int) $zone->last_check) : null,
            ])->values()->all(),
            'nameservers' => config('dns.our_nameservers', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->adminOnly($request);
        $validated = $request->validate([
            'address' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $address = DnsSecondaryServers::normalize($validated['address']);
        if ($address === null) {
            throw ValidationException::withMessages(['address' => 'Give the secondary server\'s IP address, not a host name.']);
        }
        $known = $this->secondaries->addresses();
        if (in_array($address, $known, true)) {
            throw ValidationException::withMessages(['address' => "{$address} is already a secondary."]);
        }
        if (count($known) >= DnsSecondaryServers::MAX) {
            throw ValidationException::withMessages(['address' => 'Up to '.DnsSecondaryServers::MAX.' secondaries.']);
        }

        $this->secondaries->add($address, trim((string) ($validated['label'] ?? '')) ?: null);
        $zones = $this->secondaries->applyToAllZones($this->backend);

        return back()->with('success', "{$address} may now copy {$zones} zone(s) and is notified when they change.");
    }

    public function destroy(Request $request, string $token, int $id): RedirectResponse
    {
        $this->adminOnly($request);
        abort_unless($this->secondaries->remove($id), 404);
        $this->secondaries->applyToAllZones($this->backend);

        return back()->with('success', 'The secondary can no longer copy zones from this server.');
    }

    /** Also behind `role:admin`: a secondary can read every zone on the server. */
    private function adminOnly(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
    }
}
