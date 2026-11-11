<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\MailIp;
use App\Models\User;
use App\Models\Website;
use App\Services\ActivityLogService;
use App\Services\EdgeGatewayReloader;
use App\Services\Network\IpPool;
use App\Services\Network\WebsiteIpDns;
use App\Services\Platform\ServerAddressClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System → IP Addresses (backlog U5). The admin picks which of the
 * server's addresses websites may use, marks each shared or dedicated to a
 * user or reseller, gives a private address its NAT (public) address, and
 * picks the main IP that websites use by default. A reseller sees the
 * addresses dedicated to them and gives them to their customers.
 */
class IpAddressController extends Controller
{
    public function __construct(
        private readonly IpPool $pool,
        private readonly EdgeGatewayReloader $gateway,
        private readonly ActivityLogService $activity,
    ) {}

    public function index(Request $request, ServerAddressClient $client): Response
    {
        $actor = $request->user();
        $isAdmin = $actor->hasRole('admin');
        $rows = $this->pool->visibleTo($actor)->with(['user:id,name', 'reseller:id,name'])->get();
        $discovered = $isAdmin ? $client->list() : ['addresses' => null, 'error' => null];
        $inPool = IpAddress::query()->pluck('address')->all();

        return Inertia::render('System/IpAddresses/Index', [
            'isAdmin' => $isAdmin,
            'addresses' => $rows->map(fn (IpAddress $row) => $this->present($row, $discovered['addresses']))->values()->all(),
            'discovered' => $discovered['addresses'] === null ? null : array_map(
                fn (array $found) => $found + ['in_pool' => in_array($found['address'], $inPool, true)],
                $discovered['addresses'],
            ),
            'discoveryError' => $discovered['error'],
            'owners' => $this->owners($actor),
        ]);
    }

    public function store(Request $request, ServerAddressClient $client): RedirectResponse
    {
        $validated = $this->validateRow($request, true);
        $address = $validated['address'];
        $found = $client->list()['addresses'];
        // Only an address the server has can be bound; when the list cannot
        // be read (drust down, or not supported here) the admin is trusted.
        if ($found !== null && ! in_array($address, array_column($found, 'address'), true)) {
            throw ValidationException::withMessages(['address' => "{$address} is not on any of this server's network interfaces."]);
        }
        $interface = collect($found ?? [])->firstWhere('address', $address)['interface'] ?? null;
        $first = ! IpAddress::query()->exists();
        $row = IpAddress::query()->create([
            ...$validated,
            'interface' => $interface,
            // The first address becomes the main IP, if it is shared.
            'is_main' => $first && $validated['mode'] === 'shared',
        ]);
        $this->activity->log('ip.added', null, ['address' => $row->address, 'mode' => $row->mode], $request);
        $this->gateway->reload();

        return back()->with('success', "{$row->address} added to the IP pool.".($row->is_main ? ' It is the main IP.' : ''));
    }

    public function update(Request $request, string $token, string $id): RedirectResponse
    {
        $ipAddress = IpAddress::query()->findOrFail((int) $id);
        $actor = $request->user();
        abort_unless($this->pool->visibleTo($actor)->whereKey($ipAddress->id)->exists(), 404);

        if ($actor->hasRole('admin')) {
            $validated = $this->validateRow($request, false);
            if ($ipAddress->is_main && $validated['mode'] !== 'shared') {
                throw ValidationException::withMessages(['mode' => 'The main IP stays shared; make another address the main IP first.']);
            }
        } else {
            // A reseller only passes their address on to one of their customers, or takes it back.
            $request->validate(['user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('reseller_id', $actor->id)]]);
            $validated = ['mode' => 'dedicated', 'reseller_id' => (int) $actor->id, 'user_id' => $request->filled('user_id') ? (int) $request->input('user_id') : null];
        }
        if ($error = $this->pool->ownerConflict($ipAddress, $validated['mode'], $validated['reseller_id'] ?? null, $validated['user_id'] ?? null)) {
            throw ValidationException::withMessages(['user_id' => $error]);
        }
        if ($actor->hasRole('admin') && $request->has('default_website_id')) {
            $validated['default_website_id'] = $this->defaultWebsite($ipAddress, $request->input('default_website_id'));
        }
        $ipAddress->fill($validated)->save();
        $this->activity->log('ip.updated', null, ['address' => $ipAddress->address] + $validated, $request);
        $this->gateway->reload();

        return back()->with('success', "{$ipAddress->address} saved.");
    }

    /**
     * Websites without their own address follow the main IP, so their
     * records that held the old main address move with it.
     */
    public function makeMain(Request $request, WebsiteIpDns $dns, string $token, string $id): RedirectResponse
    {
        $ipAddress = IpAddress::query()->findOrFail((int) $id);
        if ($ipAddress->isDedicated()) {
            throw ValidationException::withMessages(['address' => 'A dedicated address cannot be the main IP; make it shared first.']);
        }
        $old = $this->pool->main();
        if ($old?->is($ipAddress)) {
            return back()->with('success', "{$ipAddress->address} is already the main IP.");
        }
        IpAddress::query()->where('is_main', true)->update(['is_main' => false]);
        $ipAddress->forceFill(['is_main' => true])->save();
        // A site that had picked this address now just follows the main IP.
        Website::query()->where('ip_address_id', $ipAddress->id)->update(['ip_address_id' => null]);

        $changed = 0;
        if ($old) {
            Website::query()->whereNull('ip_address_id')->whereNotIn('type', ['alias', 'alis'])->orderBy('domain')
                ->each(function (Website $site) use ($dns, $old, $ipAddress, &$changed): void {
                    $changed += count($dns->rewrite($site, $old->dnsAddress(), $ipAddress->dnsAddress(), true)['changed']);
                });
        }
        $this->activity->log('ip.main', null, ['address' => $ipAddress->address, 'previous' => $old?->address, 'dns_records' => $changed], $request);
        $this->gateway->reload();

        return back()->with('success', "{$ipAddress->address} is now the main IP.".($changed ? " {$changed} DNS record(s) moved from {$old->dnsAddress()}." : ''));
    }

    public function destroy(Request $request, string $token, string $id): RedirectResponse
    {
        $ipAddress = IpAddress::query()->findOrFail((int) $id);
        if ($ipAddress->is_main && IpAddress::query()->whereKeyNot($ipAddress->id)->exists()) {
            throw ValidationException::withMessages(['address' => 'Make another address the main IP before removing this one.']);
        }
        $used = Website::query()->where('ip_address_id', $ipAddress->id)->pluck('domain');
        if ($used->isNotEmpty()) {
            throw ValidationException::withMessages(['address' => "Move these websites to another address first: {$used->implode(', ')}."]);
        }
        $ipAddress->delete();
        $this->activity->log('ip.removed', null, ['address' => $ipAddress->address], $request);
        $this->gateway->reload();

        return back()->with('success', "{$ipAddress->address} removed from the IP pool.");
    }

    /** @return array<string, mixed> */
    private function validateRow(Request $request, bool $creating): array
    {
        $validated = $request->validate([
            'address' => $creating ? ['required', 'ip', Rule::unique('ip_addresses', 'address')] : ['prohibited'],
            'public_address' => ['nullable', 'ip'],
            'label' => ['nullable', 'string', 'max:100'],
            'mode' => ['required', Rule::in(IpAddress::MODES)],
            'reseller_id' => ['nullable', 'integer', 'exists:users,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $validated['public_address'] = ($validated['public_address'] ?? null) ?: null;
        if ($validated['mode'] === 'shared') {
            return [...$validated, 'reseller_id' => null, 'user_id' => null];
        }

        $resellerId = isset($validated['reseller_id']) ? (int) $validated['reseller_id'] : null;
        $userId = isset($validated['user_id']) ? (int) $validated['user_id'] : null;
        if ($resellerId === null && $userId === null) {
            throw ValidationException::withMessages(['user_id' => 'A dedicated address needs a user or a reseller.']);
        }
        if ($resellerId !== null && ! User::query()->find($resellerId)?->hasRole('reseller')) {
            throw ValidationException::withMessages(['reseller_id' => 'That account is not a reseller.']);
        }
        if ($userId !== null) {
            $user = User::query()->find($userId);
            if ($user?->hasRole('admin')) {
                throw ValidationException::withMessages(['user_id' => 'An admin\'s websites use shared addresses.']);
            }
            if ($resellerId !== null && (int) $user?->reseller_id !== $resellerId) {
                throw ValidationException::withMessages(['user_id' => 'That user is not a customer of the chosen reseller.']);
            }
            // The customer's reseller keeps seeing the address and may take it back.
            $resellerId ??= $user?->reseller_id ? (int) $user->reseller_id : null;
        }

        return [...$validated, 'reseller_id' => $resellerId, 'user_id' => $userId];
    }

    /** Only a website on this address can be what its bare IP shows. */
    private function defaultWebsite(IpAddress $address, mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $onIt = Website::query()->whereKey((string) $id)->whereNotIn('type', ['alias', 'alis'])
            ->where(fn ($query) => $address->is_main
                ? $query->whereNull('ip_address_id')->orWhere('ip_address_id', $address->id)
                : $query->where('ip_address_id', $address->id))
            ->exists();
        if (! $onIt) {
            throw ValidationException::withMessages(['default_website_id' => 'Pick a website that uses this address.']);
        }

        return (string) $id;
    }

    /**
     * @param  ?list<array<string, mixed>>  $discovered
     * @return array<string, mixed>
     */
    private function present(IpAddress $row, ?array $discovered): array
    {
        $websites = Website::query()->whereNotIn('type', ['alias', 'alis'])
            ->where(fn ($query) => $row->is_main
                ? $query->whereNull('ip_address_id')->orWhere('ip_address_id', $row->id)
                : $query->where('ip_address_id', $row->id))
            ->orderBy('domain')->limit(500)->get(['id', 'domain']);
        $mailIp = MailIp::query()->whereIn('ip', array_filter([$row->address, $row->public_address]))->withCount('domains')->first();

        return [
            ...$row->only(['id', 'address', 'public_address', 'interface', 'label', 'mode', 'reseller_id', 'user_id', 'is_main', 'default_website_id']),
            'dns_address' => $row->dnsAddress(),
            'user' => $row->user?->name,
            'reseller' => $row->reseller?->name,
            'websites' => $websites->map(fn (Website $site) => ['id' => (string) $site->id, 'domain' => $site->domain])->all(),
            'mail' => $mailIp ? ['hostname' => $mailIp->hostname, 'is_default' => $mailIp->is_default, 'domains' => $mailIp->domains_count] : null,
            // Missing from the interfaces: the gateway cannot answer on it.
            'missing' => $discovered !== null && ! in_array($row->address, array_column($discovered, 'address'), true),
        ];
    }

    /** @return array{resellers: list<array{id: int, name: string}>, users: list<array{id: int, name: string, reseller_id: ?int}>} */
    private function owners(User $actor): array
    {
        $users = User::query()->orderBy('name')->get(['id', 'name', 'reseller_id', 'role']);
        $customers = $users->filter(fn (User $user) => ! $user->hasRole('admin') && ! $user->hasRole('reseller'));
        if (! $actor->hasRole('admin')) {
            $customers = $customers->where('reseller_id', $actor->id);
        }

        return [
            'resellers' => $actor->hasRole('admin')
                ? $users->filter(fn (User $user) => $user->hasRole('reseller'))->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values()->all()
                : [],
            'users' => $customers->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'reseller_id' => $user->reseller_id])->values()->all(),
        ];
    }
}
