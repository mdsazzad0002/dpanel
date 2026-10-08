<?php

namespace App\Http\Controllers\Docker\Concerns;

use App\Services\ActivityLogService;
use App\Services\Docker\DrustDockerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calls one drust Docker endpoint, writes the audit row for a change and
 * answers in the `{success, message, data}` shape every Docker page reads.
 *
 * @property-read DrustDockerClient $drust
 * @property-read ActivityLogService $activity
 */
trait CallsDrust
{
    /**
     * A read: nothing changes, so nothing is logged.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected function fetch(string $endpoint, ?array $payload = null, int $timeout = 120): JsonResponse
    {
        try {
            $result = $this->drust->call($endpoint, $payload, $timeout);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $this->clean($e)], 422);
        }

        return response()->json(['success' => true, 'message' => $result['message'], 'data' => $result['data']]);
    }

    /**
     * A change: logged as `docker.<action>` once drust has made it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $logged  what goes in the activity log; never secrets
     */
    protected function change(Request $request, string $endpoint, array $payload, string $action, array $logged, int $timeout = 120): JsonResponse
    {
        try {
            $result = $this->drust->call($endpoint, $payload, $timeout);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $this->clean($e)], 422);
        }
        $this->audit($request, $action, $logged);

        return response()->json(['success' => true, 'message' => $result['message'], 'data' => $result['data']]);
    }

    /** @param  array<string, mixed>  $logged */
    protected function audit(Request $request, string $action, array $logged): void
    {
        try {
            $this->activity->log('docker.'.$action, null, $logged, $request);
        } catch (\Throwable $e) {
            // The change is already live on the server; a missing audit row must not report it as failed.
            report($e);
        }
    }

    protected function clean(\Throwable $e): string
    {
        return (string) preg_replace('/^Failed:\s*/', '', $e->getMessage());
    }
}
