<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    /**
     * Show admin dashboard with core stats.
     */
    public function index(): Response
    {
        $roleStats = $this->collectRoleStats();

        $stats = [
            'users_total' => User::count(),
            'users_roles' => $roleStats,
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

    /**
     * @return array<int, array{name: string, count: int}>
     */
    private function collectRoleStats(): array
    {
        return Role::query()
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'name' => (string) $role->name,
                'count' => (int) $role->users_count,
            ])
            ->values()
            ->all();
    }
}
