<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    /**
     * Show admin dashboard with core stats.
     */
    public function index(): Response
    {
        $stats = [
            'users_total' => User::count(),
            'users_super_admin' => 0,
            'users_admin' => User::role('admin')->count(),
            'users_reseller' => User::role('reseller')->count(),
            'users_general' => User::role('general_user')->count(),
            'packages_total' => Package::count(),
            'packages_active' => Package::where('is_active', true)->count(),
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
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return 0;
            }

            return (int) Website::query()->where('status', 'pending')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

