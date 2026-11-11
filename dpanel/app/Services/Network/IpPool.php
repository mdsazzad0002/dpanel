<?php

namespace App\Services\Network;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The server's IP pool (backlog U5) and who may use which address.
 *
 * A shared address is open to every website. A dedicated one belongs to a
 * user (only that user's websites) or to a reseller with no user yet (only
 * the reseller's websites, picked by the reseller or an admin; the
 * reseller's customers see it once the reseller gives it to them). The
 * rules are the same for every actor, admins included: an admin changes
 * the owner first.
 */
class IpPool
{
    public function main(): ?IpAddress
    {
        return IpAddress::query()->where('is_main', true)->first();
    }

    /** An alias (and www.) answers on its main site's address, so it never has its own. */
    public function mainSite(Website $website): Website
    {
        if (in_array((string) $website->type, ['alias', 'alis'], true) && $website->parent_id) {
            return Website::query()->find($website->parent_id) ?? $website;
        }

        return $website;
    }

    /** The website's address: its own, or the main IP. */
    public function addressOf(Website $website): ?IpAddress
    {
        $site = $this->mainSite($website);
        if ($site->ip_address_id) {
            $address = IpAddress::query()->find($site->ip_address_id);
            if ($address) {
                return $address;
            }
        }

        return $this->main();
    }

    public function allows(IpAddress $address, Website $website, User $actor): bool
    {
        if (! $address->isDedicated()) {
            return true;
        }
        $site = $this->mainSite($website);
        if ($address->user_id) {
            return (int) $site->assigned_user_id === $address->user_id;
        }
        if ($address->reseller_id) {
            return (int) $site->assigned_reseller_id === $address->reseller_id
                && ($actor->hasRole('admin') || (int) $actor->id === $address->reseller_id);
        }

        // Dedicated to nobody yet: nobody's.
        return false;
    }

    /** @return Collection<int, IpAddress> main first */
    public function choicesFor(Website $website, User $actor): Collection
    {
        return IpAddress::query()->orderByDesc('is_main')->orderBy('address')->get()
            ->filter(fn (IpAddress $address) => $this->allows($address, $website, $actor))
            ->values();
    }

    /** The pool rows an actor manages on the IP Addresses page. */
    public function visibleTo(User $actor): Builder
    {
        $query = IpAddress::query()->orderByDesc('is_main')->orderBy('address');
        if ($actor->hasRole('admin')) {
            return $query;
        }

        return $query->where('reseller_id', $actor->id);
    }

    /**
     * Who could own a dedicated address: an owner's websites must be the
     * only ones on it. Null when that holds, else the reason.
     */
    public function ownerConflict(IpAddress $address, string $mode, ?int $resellerId, ?int $userId): ?string
    {
        if ($mode !== 'dedicated') {
            return null;
        }
        $others = Website::query()->where('ip_address_id', $address->id)
            ->when($userId, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('assigned_user_id')->orWhere('assigned_user_id', '!=', $userId)))
            ->when(! $userId && $resellerId, fn ($query) => $query->where(fn ($inner) => $inner->whereNull('assigned_reseller_id')->orWhere('assigned_reseller_id', '!=', $resellerId)))
            ->pluck('domain');

        return $others->isEmpty() ? null : 'These websites use '.$address->address.' and belong to someone else: '.$others->implode(', ').'. Move them first.';
    }

    /** Pool rows matching an address, local or public: the IP mail sends from is either. */
    public function findByAnyAddress(string $ip): ?IpAddress
    {
        return IpAddress::query()->where('address', $ip)->orWhere('public_address', $ip)->first();
    }

    /**
     * Whether a dedicated pool address may send a domain's mail: only for
     * a domain whose website belongs to the address's owner. Null when it
     * may (or the address is not in the pool), else the reason.
     */
    public function mailConflict(string $ip, string $domain): ?string
    {
        $address = $this->findByAnyAddress($ip);
        if (! $address || ! $address->isDedicated()) {
            return null;
        }
        $website = Website::query()->whereRaw('LOWER(domain) = ?', [strtolower(trim($domain))])->first();
        $site = $website ? $this->mainSite($website) : null;
        $owned = $site && ($address->user_id
            ? (int) $site->assigned_user_id === $address->user_id
            : (int) $site->assigned_reseller_id === (int) $address->reseller_id);

        return $owned ? null : "{$ip} is dedicated to another account in the IP pool, so {$domain} cannot send from it.";
    }
}
