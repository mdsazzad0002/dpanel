<?php

namespace App\Services\Branding;

use App\Models\Branding;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Which look a user sees (backlog U4): a reseller and their customers get
 * the reseller's, everyone else the server's. A field the reseller left
 * empty falls back to the server's, then to dPanel's own.
 */
class BrandingResolver
{
    /** @return array{name: string, logo_url: string|null, color: string|null} */
    public function forUser(?User $user): array
    {
        $rows = $this->rows($user);
        $pick = function (string $field) use ($rows): ?string {
            foreach ($rows as $row) {
                $value = trim((string) $row->{$field});
                if ($value !== '') {
                    return $value;
                }
            }

            return null;
        };
        $logo = collect($rows)->first(fn (Branding $row) => trim((string) $row->logo_path) !== '');

        return [
            'name' => $pick('panel_name') ?? (string) config('app.name', 'dPanel'),
            'logo_url' => $logo ? route('branding.logo', ['branding' => $logo->id, 'v' => $logo->updated_at?->timestamp]) : null,
            'color' => $pick('primary_color'),
        ];
    }

    /** The reseller whose look this user gets, or null for the server's. */
    public function resellerFor(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }
        if ($user->hasRole('reseller')) {
            return (int) $user->id;
        }

        return $user->reseller_id ? (int) $user->reseller_id : null;
    }

    /** @return list<Branding> most specific first */
    private function rows(?User $user): array
    {
        try {
            if (! Schema::hasTable('brandings')) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }
        $reseller = $this->resellerFor($user);
        $rows = Branding::query()
            ->whereNull('owner_id')
            ->when($reseller !== null, fn ($query) => $query->orWhere('owner_id', $reseller))
            ->get();

        return $rows->sortBy(fn (Branding $row) => $row->owner_id === null ? 1 : 0)->values()->all();
    }
}
