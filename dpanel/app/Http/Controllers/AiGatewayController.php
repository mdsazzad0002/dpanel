<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayRequestLog;
use App\Models\AiGatewayUsageRecord;
use App\Services\AiGateway\AiGatewayService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayController extends Controller
{
    public function __construct(private readonly AiGatewayService $gateway)
    {
    }

    public function index(Request $request): Response
    {
        $today = now()->toDateString();
        $last7d = now()->subDays(6)->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $period = $this->withoutCost(AiGatewayUsageRecord::summarise($last7d, $today));
        $month = $this->withoutCost(AiGatewayUsageRecord::summarise($monthStart, $today));

        // 7-day series for the top requests chart.
        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $sum = AiGatewayUsageRecord::summarise($date, $date);
            $series[] = [
                'date' => $date,
                'requests' => $sum['requests'],
                'tokens' => $sum['input_tokens'] + $sum['output_tokens'],
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

        $perProviderRequests = AiGatewayUsageRecord::query()
            ->whereBetween('usage_date', [$last7d, $today])
            ->selectRaw('provider_id, SUM(requests) as requests')
            ->groupBy('provider_id')
            ->with('provider:id,name,driver')
            ->get()
            ->map(fn ($row): ?array => $row->provider_id ? [
                'name' => $row->provider->name ?? 'Unknown',
                'driver' => $row->provider->driver ?? null,
                'requests' => (int) $row->requests,
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
            ]);

        // ------------------------------------------------------------------
        // Usage explorer (filterable range, folded into the same page).
        // ------------------------------------------------------------------
        $from = $request->input('from', $monthStart);
        $to = $request->input('to', $today);
        $modelId = $request->integer('model') ?: null;
        $providerId = $request->integer('provider') ?: null;

        $usageTotals = $this->withoutCost(AiGatewayUsageRecord::summarise($from, $to, $modelId, $providerId));

        $usageDaily = AiGatewayUsageRecord::query()
            ->whereBetween('usage_date', [$from, $to])
            ->when($modelId, fn ($q) => $q->where('model_id', $modelId))
            ->when($providerId, fn ($q) => $q->where('provider_id', $providerId))
            ->selectRaw('usage_date, SUM(requests) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->usage_date,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
            ])
            ->values();

        $usageByModel = AiGatewayUsageRecord::query()
            ->whereBetween('usage_date', [$from, $to])
            ->when($providerId, fn ($q) => $q->where('provider_id', $providerId))
            ->selectRaw('model_id, SUM(requests) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
            ->groupBy('model_id')
            ->with('model:id,name,display_name,provider_id')
            ->with('model.provider:id,name')
            ->get()
            ->map(fn ($row): array => [
                'model_id' => $row->model_id,
                'model_name' => $row->model?->display_name ?: $row->model?->name,
                'provider' => $row->model?->provider?->name,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
            ])
            ->values();

        $usageByProvider = AiGatewayUsageRecord::query()
            ->whereBetween('usage_date', [$from, $to])
            ->when($modelId, fn ($q) => $q->where('model_id', $modelId))
            ->selectRaw('provider_id, SUM(requests) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens')
            ->groupBy('provider_id')
            ->with('provider:id,name,driver')
            ->get()
            ->map(fn ($row): array => [
                'provider_id' => $row->provider_id,
                'provider_name' => $row->provider?->name,
                'driver' => $row->provider?->driver,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
            ])
            ->values();

        return Inertia::render('AiGateway/Dashboard', [
            'stats' => [
                'activeProviders' => AiGatewayProvider::where('is_active', true)->count(),
                'totalProviders' => AiGatewayProvider::count(),
                'models' => AiGatewayModel::count(),
                'period' => $period,
                'month' => $month,
            ],
            'series' => $series,
            'providers' => $providers,
            'perProviderRequests' => $perProviderRequests,
            'recentLogs' => $recentLogs,
            'usage' => [
                'filters' => ['from' => $from, 'to' => $to, 'model' => $modelId, 'provider' => $providerId],
                'totals' => $usageTotals,
                'daily' => $usageDaily,
                'byModel' => $usageByModel,
                'byProvider' => $usageByProvider,
                'models' => AiGatewayModel::query()->with('provider:id,name')->orderBy('name')->get(['id', 'name', 'display_name', 'provider_id']),
                'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    /**
     * Dashboard shows no payment/pricing information — strip the cost
     * figure out of usage summaries before they reach the page props.
     */
    private function withoutCost(array $summary): array
    {
        unset($summary['cost']);

        return $summary;
    }
}
