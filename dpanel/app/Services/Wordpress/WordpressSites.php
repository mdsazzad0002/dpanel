<?php

namespace App\Services\Wordpress;

use App\Models\User;
use App\Models\Website;
use App\Services\Website\WordpressInstallService;
use Illuminate\Support\Collection;

/** Every WordPress site a user can see, for the one WordPress page (W1). */
class WordpressSites
{
    public function __construct(private readonly WordpressInstallService $install) {}

    /** @return Collection<int, Website> */
    public function candidates(User $user): Collection
    {
        return Website::query()->visibleTo($user)
            ->where('type', 'primary')
            ->where(fn ($query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'))
            ->where(fn ($query) => $query->whereNull('runtime')->orWhere('runtime', 'php'))
            ->whereNotNull('site_owner')
            ->orderBy('domain')
            ->get();
    }

    /** @return list<array{id: string, domain: string, version: string, php_version: string, serving_state: string}> */
    public function list(User $user): array
    {
        $sites = [];
        foreach ($this->candidates($user) as $website) {
            $version = $this->install->installedVersion($website->toArray());
            if ($version === null) {
                continue;
            }
            $sites[] = [
                'id' => (string) $website->id,
                'domain' => (string) $website->domain,
                'version' => $version,
                'php_version' => (string) $website->php_version,
                'serving_state' => (string) ($website->serving_state ?? 'active'),
            ];
        }

        return $sites;
    }
}
