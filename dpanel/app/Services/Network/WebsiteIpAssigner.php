<?php

namespace App\Services\Network;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\Website;
use Illuminate\Validation\ValidationException;

/**
 * Moves a website to another address of the pool (backlog U5): saves it
 * (the model's save reloads the gateway for the domain, which also reads
 * the pool's rules again) and rewrites the zone's records.
 */
class WebsiteIpAssigner
{
    public function __construct(
        private readonly IpPool $pool,
        private readonly WebsiteIpDns $dns,
    ) {}

    /**
     * @return array{address: ?IpAddress, dns: array{zone: ?string, changed: list<string>, skipped: ?string}}
     *
     * @throws ValidationException
     */
    public function assign(Website $website, ?IpAddress $address, User $actor): array
    {
        if ($this->pool->mainSite($website)->id !== $website->id) {
            throw ValidationException::withMessages(['ip_address_id' => 'An alias answers on its main website\'s address; change it there.']);
        }
        if ($address && ! $this->pool->allows($address, $website, $actor)) {
            throw ValidationException::withMessages(['ip_address_id' => $address->address.' is dedicated to another account.']);
        }
        $old = $this->pool->addressOf($website);
        // The main IP is stored as null, so a site follows it when the admin picks another main IP.
        $website->forceFill(['ip_address_id' => $address && ! $address->is_main ? $address->id : null])->save();
        $new = $this->pool->addressOf($website);

        $dns = $new
            ? $this->dns->rewrite($website, $old?->dnsAddress(), $new->dnsAddress())
            : ['zone' => null, 'changed' => [], 'skipped' => 'The IP pool has no main address yet.'];

        return ['address' => $new, 'dns' => $dns];
    }
}
