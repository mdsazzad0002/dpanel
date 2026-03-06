<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRequest;
use App\Models\Package;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ResellerController extends Controller
{
    /**
     * Display reseller dashboard.
     */
    public function index(): Response
    {
        $websiteRequests = $this->readWebsiteRequests();
        $databaseRequests = $this->readDatabaseRequests();

        $stats = [
            'website_requests_total' => $websiteRequests->count(),
            'website_requests_pending' => $websiteRequests->where('status', 'pending')->count(),
            'database_requests_total' => $databaseRequests->count(),
            'database_requests_pending' => $databaseRequests->where('status', 'pending')->count(),
            'active_packages' => Package::where('is_active', true)->count(),
        ];

        $recentWebsiteRequests = $websiteRequests
            ->take(6)
            ->map(fn (Website $item): array => [
                'id' => (string) ($item->id ?? ''),
                'domain' => (string) ($item->domain ?? '-'),
                'php_version' => (string) ($item->php_version ?? '-'),
                'status' => (string) ($item->status ?? 'pending'),
                'created_at' => optional($item->created_at)->toDateTimeString() ?? '',
            ])
            ->values()
            ->all();

        $recentDatabaseRequests = $databaseRequests
            ->take(6)
            ->map(fn (DatabaseRequest $item): array => [
                'id' => (string) ($item->id ?? ''),
                'domain' => (string) ($item->domain ?? '-'),
                'database_name' => (string) ($item->database_name ?? '-'),
                'status' => (string) ($item->status ?? 'pending'),
                'created_at' => optional($item->created_at)->toDateTimeString() ?? '',
            ])
            ->values()
            ->all();

        return Inertia::render('ResellerPanel', [
            'stats' => $stats,
            'recentWebsiteRequests' => $recentWebsiteRequests,
            'recentDatabaseRequests' => $recentDatabaseRequests,
        ]);
    }

    private function readWebsiteRequests()
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return collect();
            }

            return Website::query()->orderByDesc('created_at')->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    private function readDatabaseRequests()
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('database_requests')) {
                return collect();
            }

            return DatabaseRequest::query()->orderByDesc('created_at')->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }
}
