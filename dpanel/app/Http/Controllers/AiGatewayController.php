<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayAgent;
use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayRequestLog;
use App\Models\AiGatewayRoutingRule;
use App\Models\AiGatewayTask;
use App\Models\AiGatewayUsageRecord;
use App\Services\AiGateway\AiGatewayService;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayController extends Controller
{
    public function __construct(private readonly AiGatewayService $gateway)
    {
    }

    public function index(): Response
    {
        $today = now()->toDateString();
        $last7d = now()->subDays(6)->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $period = AiGatewayUsageRecord::summarise($last7d, $today);
        $month = AiGatewayUsageRecord::summarise($monthStart, $today);

        // 7-day series for the dashboard chart.
        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $sum = AiGatewayUsageRecord::summarise($date, $date);
            $series[] = [
                'date' => $date,
                'requests' => $sum['requests'],
                'tokens' => $sum['input_tokens'] + $sum['output_tokens'],
                'cost' => $sum['cost'],
            ];
        }

        $providers = AiGatewayProvider::query()
            ->withCount('models')
            ->orderByDesc('is_active')
            ->orderByDesc('weight')
            ->get()
            ->map(fn (AiGatewayProvider $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'driver' => $p->driver,
                'driver_label' => $p->getDriverLabel(),
                'slug' => $p->slug,
                'is_active' => $p->is_active,
                'default_model' => $p->default_model,
                'models_count' => $p->models_count,
                'last_test_status' => $p->last_test_status,
            ]);

        $perProviderUsage = AiGatewayUsageRecord::query()
            ->whereBetween('usage_date', [$last7d, $today])
            ->selectRaw('provider_id, SUM(requests) as requests, SUM(cost) as cost')
            ->groupBy('provider_id')
            ->with('provider:id,name,driver')
            ->get()
            ->map(fn ($row): ?array => $row->provider_id ? [
                'name' => $row->provider->name ?? 'Unknown',
                'driver' => $row->provider->driver ?? null,
                'requests' => (int) $row->requests,
                'cost' => round((float) $row->cost, 4),
            ] : null)
            ->filter()
            ->values();

        $recentLogs = AiGatewayRequestLog::query()
            ->with(['provider:id,name', 'model:id,name,display_name'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (AiGatewayRequestLog $l): array => [
                'id' => $l->id,
                'created_at' => $l->created_at?->toDateTimeString(),
                'status' => $l->status,
                'provider' => $l->provider?->name,
                'model' => $l->model,
                'operation' => $l->operation,
                'latency_ms' => $l->latency_ms,
                'cost' => $l->cost,
            ]);

        return Inertia::render('AiGateway/Dashboard', [
            'stats' => [
                'activeProviders' => AiGatewayProvider::where('is_active', true)->count(),
                'totalProviders' => AiGatewayProvider::count(),
                'models' => AiGatewayModel::count(),
                'agents' => AiGatewayAgent::where('is_active', true)->count(),
                'rules' => AiGatewayRoutingRule::where('is_active', true)->count(),
                'tasksToday' => AiGatewayTask::whereDate('created_at', $today)->count(),
                'period' => $period,
                'month' => $month,
            ],
            'series' => $series,
            'providers' => $providers,
            'perProviderUsage' => $perProviderUsage,
            'recentLogs' => $recentLogs,
        ]);
    }
}
