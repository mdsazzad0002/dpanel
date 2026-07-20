<?php

namespace App\Http\Controllers;

use App\Models\SshConnectionTest;
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
            'ssh_failures_24h' => $this->countSshFailuresLastDay(),
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
            'sshFailurePanel' => $this->buildSshFailurePanel(),
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

    private function countSshFailuresLastDay(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('ssh_connection_tests')) {
                return 0;
            }

            return (int) SshConnectionTest::query()
                ->where('status', 'failed')
                ->where('tested_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{has_failures: bool, recent_failures: array<int, array<string, mixed>>, suggestions: array<int, string>}
     */
    private function buildSshFailurePanel(): array
    {
        $fallback = [
            'has_failures' => false,
            'recent_failures' => [],
            'suggestions' => $this->sshSetupSuggestions(),
        ];

        try {
            if (! DB::getSchemaBuilder()->hasTable('ssh_connection_tests')) {
                return $fallback;
            }

            $failures = SshConnectionTest::query()
                ->with('server:id,name,host,port,username')
                ->where('status', 'failed')
                ->latest('tested_at')
                ->limit(8)
                ->get();

            return [
                'has_failures' => $failures->isNotEmpty(),
                'recent_failures' => $failures->map(fn (SshConnectionTest $test): array => [
                    'id' => $test->id,
                    'tested_at' => optional($test->tested_at)->toDateTimeString(),
                    'error_output' => (string) ($test->error_output ?? 'Unknown SSH error'),
                    'server' => [
                        'name' => (string) ($test->server?->name ?? 'Unknown server'),
                        'host' => (string) ($test->server?->host ?? '-'),
                        'port' => (int) ($test->server?->port ?? 22),
                        'username' => (string) ($test->server?->username ?? '-'),
                    ],
                ])->all(),
                'suggestions' => $this->sshSetupSuggestions(),
            ];
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * @return array<int, string>
     */
    private function sshSetupSuggestions(): array
    {
        return [
            'Verify SSH service: sudo systemctl status ssh && sudo systemctl restart ssh',
            'Open firewall for SSH port: sudo ufw allow 22/tcp && sudo ufw status',
            'Confirm server host/port from panel matches server IP and sshd port in /etc/ssh/sshd_config',
            'Confirm credentials: username, password/key, and key passphrase if key authentication is used',
            'Test manually from panel host: ssh -p <port> <user>@<host>',
        ];
    }
}
