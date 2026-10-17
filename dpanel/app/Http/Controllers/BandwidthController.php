<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Bandwidth\BandwidthUsageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BandwidthController extends Controller
{
    public function __construct(private readonly BandwidthUsageService $bandwidth) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $request->validate(['month' => ['sometimes', 'date_format:Y-m']]);
        $month = (string) $request->query('month', now('UTC')->format('Y-m'));

        $isAdmin = $actor->hasRole('admin');
        $showOwner = $isAdmin || $actor->hasRole('reseller');
        $report = $this->bandwidth->report($actor, $month);
        $rows = $report['rows']->map(fn (array $row) => [...$row, 'owner' => $showOwner ? $row['owner'] : null]);

        return Inertia::render('Bandwidth/Index', [
            'scope' => match (true) {
                $isAdmin => 'All websites on this server',
                $actor->hasRole('reseller') => 'Your and your customers\' websites',
                default => 'Your websites',
            },
            'showOwner' => $showOwner,
            'canManageWebsites' => $actor->hasAnyRole(['admin', 'reseller']) || $actor->hasAccess('manage_websites'),
            'month' => $month,
            'months' => collect(range(0, 11))->map(fn (int $offset) => now('UTC')->subMonths($offset)->format('Y-m')),
            'available' => $report['available'],
            'rows' => $rows,
            'dailyRows' => $report['daily'],
            'totals' => $report['totals'],
        ]);
    }
}
