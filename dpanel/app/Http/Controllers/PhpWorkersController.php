<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-site PHP-FPM worker limits (pm.max_children) and live usage, read
 * from and written through drust's /api/v1/php/workers.
 */
class PhpWorkersController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activity,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Php/PhpWorkers');
    }

    public function data(): JsonResponse
    {
        // This request itself runs in a PHP worker; drust counts it as idle.
        return $this->forward(fn ($http, string $url) => $http->get($url, ['observer_pid' => getmypid()]));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner' => ['required', 'string', 'max:64', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'max_children' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = isset($validated['max_children']) ? (int) $validated['max_children'] : null;
        $response = $this->forward(fn ($http, string $url) => $http->post($url, [
            'owner' => $validated['owner'],
            'max_children' => $limit,
        ]));

        if ($response->getData(true)['success'] ?? false) {
            $this->activity->log('php.workers.update', null, [
                'owner' => $validated['owner'],
                'max_children' => $limit ?? 'auto',
            ], $request);
        }

        return $response;
    }

    /**
     * @param  callable(\Illuminate\Http\Client\PendingRequest, string): \Illuminate\Http\Client\Response  $send
     */
    private function forward(callable $send): JsonResponse
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            return response()->json(['success' => false, 'message' => 'drust API is not configured.'], 503);
        }

        $http = Http::acceptJson()->asJson()->timeout(30);
        $token = trim((string) config('dpanel.execution_api_token', ''));
        if ($token !== '') {
            $http = $http->withToken($token);
        }

        try {
            $response = $send($http, rtrim($baseUrl, '/').'/api/v1/php/workers');
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        $json = $response->json();
        if (! is_array($json)) {
            $message = $response->status() === 404
                ? 'drust does not support PHP worker management yet. Update and restart drust.'
                : 'Unexpected response from drust.';

            return response()->json(['success' => false, 'message' => $message], 502);
        }

        $ok = $response->successful() && (bool) ($json['success'] ?? false);

        return response()->json([
            'success' => $ok,
            'message' => preg_replace('/^Failed:\s*/', '', (string) ($json['message'] ?? '')),
            'data' => $json['data'] ?? null,
        ], $ok ? 200 : 422);
    }
}
