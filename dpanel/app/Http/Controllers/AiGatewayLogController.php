<?php

namespace App\Http\Controllers;

use App\Models\AiGatewayAgent;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayRequestLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiGatewayLogController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->input('status');
        $provider = $request->integer('provider');
        $q = trim((string) $request->input('q'));

        $logs = AiGatewayRequestLog::query()
            ->with(['provider:id,name', 'model:id,name,display_name', 'agent:id,name'])
            ->when($status && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($provider, fn ($query) => $query->where('provider_id', $provider))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('model', 'like', "%{$q}%")
                        ->orWhere('trace_id', 'like', "%{$q}%")
                        ->orWhere('error_message', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(function (AiGatewayRequestLog $log): array {
                return [
                    'id' => $log->id,
                    'trace_id' => $log->trace_id,
                    'channel' => $log->channel,
                    'operation' => $log->operation,
                    'status' => $log->status,
                    'model' => $log->model,
                    'provider_name' => $log->provider?->name,
                    'agent_name' => $log->agent?->name,
                    'input_tokens' => $log->input_tokens,
                    'output_tokens' => $log->output_tokens,
                    'cost' => $log->cost,
                    'latency_ms' => $log->latency_ms,
                    'error_message' => $log->error_message,
                    'created_at' => $log->created_at?->toDateTimeString(),
                ];
            });

        return Inertia::render('AiGateway/Logs', [
            'logs' => $logs,
            'filters' => ['status' => $status ?? 'all', 'provider' => $provider, 'q' => $q],
            'providers' => AiGatewayProvider::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(AiGatewayRequestLog $log): Response
    {
        $log->load(['provider:id,name', 'model:id,name,display_name', 'agent:id,name']);

        return Inertia::render('AiGateway/Logs/Show', [
            'log' => [
                'id' => $log->id,
                'trace_id' => $log->trace_id,
                'channel' => $log->channel,
                'operation' => $log->operation,
                'status' => $log->status,
                'model' => $log->model,
                'provider_name' => $log->provider?->name,
                'agent_name' => $log->agent?->name,
                'request_payload' => $log->request_payload,
                'response_snippet' => $log->response_snippet,
                'error_message' => $log->error_message,
                'input_tokens' => $log->input_tokens,
                'output_tokens' => $log->output_tokens,
                'cost' => $log->cost,
                'latency_ms' => $log->latency_ms,
                'created_at' => $log->created_at?->toDateTimeString(),
            ],
        ]);
    }

    public function clear(Request $request): RedirectResponse
    {
        $status = $request->input('status');

        $query = AiGatewayRequestLog::query();
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $deleted = $query->delete();

        return redirect()->route('ai-gateway.logs.index')->with('success', "Deleted {$deleted} log entr".($deleted === 1 ? 'y' : 'ies').'.');
    }
}
