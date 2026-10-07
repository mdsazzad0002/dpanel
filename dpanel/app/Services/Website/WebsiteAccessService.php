<?php

namespace App\Services\Website;

use App\Models\User;
use App\Models\Website;

class WebsiteAccessService
{
    /**
     * @return array<string, mixed>
     */
    public function findAuthorizedWebsiteOrFail(string $id, ?User $actor = null): array
    {
        $website = Website::query()
            ->visibleTo($actor ?? request()->user())
            ->firstWhere('id', $id);

        abort_if($website === null, 404);

        $normalized = $website->toArray();
        abort_unless($this->actorCanAccessWebsite($normalized, $actor ?? request()->user()), 403);

        return $normalized;
    }

    /**
     * Every site the actor may see, each with its public URL and absolute
     * document root, for the file manager's site switcher. Sites of
     * $preferOwner's account come first, primaries before aliases.
     *
     * @return list<array{id: string, domain: string, type: string, url: string, doc_root: string}>
     */
    public function browsableSites(?User $actor = null, string $preferOwner = ''): array
    {
        return Website::query()
            ->visibleTo($actor ?? request()->user())
            ->when($preferOwner !== '', fn ($query) => $query->orderByRaw('CASE WHEN site_owner = ? THEN 0 ELSE 1 END', [$preferOwner]))
            ->orderByRaw("CASE WHEN type = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('domain')
            ->get(['id', 'domain', 'type', 'root_path', 'start_directory', 'enable_ssl'])
            ->filter(fn (Website $site): bool => trim((string) $site->domain) !== '')
            ->map(function (Website $site): array {
                $domain = strtolower(trim((string) $site->domain));
                $root = rtrim(str_replace('\\', '/', (string) $site->root_path), '/');
                $start = trim(str_replace('\\', '/', (string) $site->start_directory), '/');

                return [
                    'id' => (string) $site->id,
                    'domain' => $domain,
                    'type' => (string) ($site->type ?? ''),
                    'url' => ($site->enable_ssl ? 'https' : 'http').'://'.$domain,
                    'doc_root' => $root !== '' && $start !== '' ? $root.'/'.$start : $root,
                ];
            })
            ->values()
            ->all();
    }

    public function actorCanAccessWebsite(array $website, ?User $actor = null): bool
    {
        if ($actor === null) {
            return false;
        }

        if ($actor->hasRole('admin')) {
            return true;
        }

        if ($actor->hasRole('reseller')) {
            return (int) ($website['assigned_reseller_id'] ?? 0) === (int) $actor->id;
        }

        if ($actor->hasRole('general') || $actor->hasRole('general_user')) {
            return (int) ($website['assigned_user_id'] ?? 0) === (int) $actor->id;
        }

        return false;
    }
}
