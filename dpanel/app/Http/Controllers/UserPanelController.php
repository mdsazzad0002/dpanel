<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRequest;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserPanelController extends Controller
{
    /**
     * Display complete individual user panel.
     */
    public function show(): Response
    {
        $user = request()->user();

        return Inertia::render('IndividualUserPanel', [
            'panelUser' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'email_verified_at' => $user?->email_verified_at,
                'roles' => $user?->getRoleNames() ?? [],
                'permissions' => $user?->getPermissionNames() ?? [],
            ],
            'requestStats' => [
                'website_requests_total' => $this->safeCountWebsites(),
                'website_requests_pending' => $this->safeCountPendingWebsites(),
                'database_requests_total' => $this->safeCountDatabaseRequests(),
                'database_requests_pending' => $this->safeCountPendingDatabaseRequests(),
            ],
        ]);
    }

    private function safeCountWebsites(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return 0;
            }

            return (int) Website::query()->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function safeCountPendingWebsites(): int
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

    private function safeCountDatabaseRequests(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('database_requests')) {
                return 0;
            }

            return (int) DatabaseRequest::query()->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function safeCountPendingDatabaseRequests(): int
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('database_requests')) {
                return 0;
            }

            return (int) DatabaseRequest::query()->where('status', 'pending')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
