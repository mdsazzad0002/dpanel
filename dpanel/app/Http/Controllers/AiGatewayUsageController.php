<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayUsageRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayUsageController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $modelId = $request->integer('model') ?: null;
        $providerId = $request->integer('provider') ?: null;

        $totals = AiGatewayUsageRecord::summarise($from, $to, $modelId, $providerId);

        // Daily series for charts.
        $daily = AiGatewayUsageRecord::query()
            ->whereBetween('usage_date', [$from, $to])
            ->when($modelId, fn ($q) => $q->where('model_id', $modelId))
            ->when($providerId, fn ($q) => $q->where('provider_id', $providerId))
            ->selectRaw('usage_date, SUM(requests) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cost) as cost')
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get()
            ->map(fn ($row): array => [
                'date' => $row->usage_date,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
                'cost' => round((float) $row->cost, 4),
            ])
            ->values();

        // Breakdown by model.
        $byModel = AiGatewayUsageRecord::query()
            ->whereBetween('usage_date', [$from, $to])
            ->when($providerId, fn ($q) => $q->where('provider_id', $providerId))
            ->selectRaw('model_id, SUM(requests) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cost) as cost')
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
                'cost' => round((float) $row->cost, 4),
            ])
            ->values();

        // Breakdown by provider.
        $byProvider = AiGatewayUsageRecord::query()
            ->whereBetween('usage_date', [$from, $to])
            ->when($modelId, fn ($q) => $q->where('model_id', $modelId))
            ->selectRaw('provider_id, SUM(requests) as requests, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(cost) as cost')
            ->groupBy('provider_id')
            ->with('provider:id,name,driver')
            ->get()
            ->map(fn ($row): array => [
                'provider_id' => $row->provider_id,
                'provider_name' => $row->provider?->name,
                'driver' => $row->provider?->driver,
                'requests' => (int) $row->requests,
                'tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
                'cost' => round((float) $row->cost, 4),
            ])
            ->values();

        return Inertia::render('AiGateway/Usage', [
            'filters' => ['from' => $from, 'to' => $to, 'model' => $modelId, 'provider' => $providerId],
            'totals' => $totals,
            'daily' => $daily,
            'byModel' => $byModel,
            'byProvider' => $byProvider,
            'models' => AiGatewayModel::query()->with('provider:id,name')->orderBy('name')->get(['id', 'name', 'display_name', 'provider_id']),
            'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
