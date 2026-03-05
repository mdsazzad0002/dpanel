<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class UserPanelController extends Controller
{
    private const WEBSITE_REQUESTS_FILE = 'website-requests.json';
    private const DATABASE_REQUESTS_FILE = 'database-requests.json';

    /**
     * Display complete individual user panel.
     */
    public function show(): Response
    {
        $user = request()->user();
        $websiteRequests = collect($this->readJson(self::WEBSITE_REQUESTS_FILE));
        $databaseRequests = collect($this->readJson(self::DATABASE_REQUESTS_FILE));

        $activeSubscription = $user?->subscriptions()
            ->with('package')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('started_at')
            ->first();

        $subscriptionHistory = $user?->subscriptions()
            ->with('package')
            ->latest('created_at')
            ->limit(10)
            ->get();

        return Inertia::render('IndividualUserPanel', [
            'panelUser' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'email_verified_at' => $user?->email_verified_at,
                'roles' => $user?->getRoleNames() ?? [],
                'permissions' => $user?->getPermissionNames() ?? [],
            ],
            'activeSubscription' => $activeSubscription,
            'subscriptionQuotas' => $activeSubscription?->quotas(),
            'subscriptionHistory' => $subscriptionHistory,
            'requestStats' => [
                'website_requests_total' => $websiteRequests->count(),
                'website_requests_pending' => $websiteRequests->where('status', 'pending')->count(),
                'database_requests_total' => $databaseRequests->count(),
                'database_requests_pending' => $databaseRequests->where('status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readJson(string $file): array
    {
        if (! Storage::exists($file)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get($file), true);

        return is_array($decoded) ? $decoded : [];
    }
}
