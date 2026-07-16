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
