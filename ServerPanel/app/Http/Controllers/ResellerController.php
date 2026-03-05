<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ResellerController extends Controller
{
    private const WEBSITE_REQUESTS_FILE = 'website-requests.json';
    private const DATABASE_REQUESTS_FILE = 'database-requests.json';

    /**
     * Display reseller dashboard.
     */
    public function index(): Response
    {
        $websiteRequests = collect($this->readJson(self::WEBSITE_REQUESTS_FILE));
        $databaseRequests = collect($this->readJson(self::DATABASE_REQUESTS_FILE));

        $stats = [
            'website_requests_total' => $websiteRequests->count(),
            'website_requests_pending' => $websiteRequests->where('status', 'pending')->count(),
            'database_requests_total' => $databaseRequests->count(),
            'database_requests_pending' => $databaseRequests->where('status', 'pending')->count(),
            'active_subscriptions' => Subscription::query()
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                })
                ->count(),
            'active_packages' => Package::where('is_active', true)->count(),
        ];

        $recentWebsiteRequests = $websiteRequests
            ->sortByDesc('created_at')
            ->take(6)
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'domain' => (string) ($item['domain'] ?? '-'),
                'php_version' => (string) ($item['php_version'] ?? '-'),
                'status' => (string) ($item['status'] ?? 'pending'),
                'created_at' => (string) ($item['created_at'] ?? ''),
            ])
            ->values()
            ->all();

        $recentDatabaseRequests = $databaseRequests
            ->sortByDesc('created_at')
            ->take(6)
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'domain' => (string) ($item['domain'] ?? '-'),
                'database_name' => (string) ($item['database_name'] ?? '-'),
                'status' => (string) ($item['status'] ?? 'pending'),
                'created_at' => (string) ($item['created_at'] ?? ''),
            ])
            ->values()
            ->all();

        return Inertia::render('ResellerPanel', [
            'stats' => $stats,
            'recentWebsiteRequests' => $recentWebsiteRequests,
            'recentDatabaseRequests' => $recentDatabaseRequests,
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

