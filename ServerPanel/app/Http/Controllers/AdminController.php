<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    private const WEBSITE_REQUESTS_FILE = 'website-requests.json';

    /**
     * Show admin dashboard with core stats.
     */
    public function index(): Response
    {
        $stats = [
            'users_total' => User::count(),
            'users_super_admin' => User::role('super_admin')->count(),
            'users_reseller' => User::role('reseller')->count(),
            'users_general' => User::role('general_user')->count(),
            'packages_total' => Package::count(),
            'packages_active' => Package::where('is_active', true)->count(),
            'subscriptions_total' => Subscription::count(),
            'subscriptions_active' => Subscription::query()
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->count(),
            'website_requests_pending' => $this->countPendingWebsiteRequests(),
        ];

        $recentUsers = User::query()
            ->latest('id')
            ->limit(6)
            ->get(['id', 'name', 'email', 'created_at'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => optional($user->created_at)->toDateTimeString(),
            ])
            ->all();

        return Inertia::render('AdminPanel', [
            'stats' => $stats,
            'recentUsers' => $recentUsers,
        ]);
    }

    /**
     * Count pending website request commands.
     */
    private function countPendingWebsiteRequests(): int
    {
        if (! Storage::exists(self::WEBSITE_REQUESTS_FILE)) {
            return 0;
        }

        $decoded = json_decode((string) Storage::get(self::WEBSITE_REQUESTS_FILE), true);
        if (! is_array($decoded)) {
            return 0;
        }

        return collect($decoded)
            ->where('status', 'pending')
            ->count();
    }
}

