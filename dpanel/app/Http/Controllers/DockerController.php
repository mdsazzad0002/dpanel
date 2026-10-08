<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\Docker\DrustDockerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Docker containers and images on this server. Docker access is root access
 * in all but name, so every route is admin-only; drust validates each value
 * again before it reaches the docker CLI.
 */
class DockerController extends Controller
{
    private const NAME = 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/';

    private const IMAGE = 'regex:/^[A-Za-z0-9][A-Za-z0-9_.\/:@-]{0,254}$/';

    public function __construct(
        private readonly DrustDockerClient $drust,
        private readonly ActivityLogService $activity,
    ) {
    }

    public function containers(): Response
    {
        return Inertia::render('Docker/Containers');
    }

    public function images(): Response
    {
        return Inertia::render('Docker/Images');
    }

    public function status(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->drust->status()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function containerAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:start,stop,restart,remove'],
            'id' => ['required', 'string', self::NAME],
        ]);

        return $this->act($request, $validated['action'], ['id' => $validated['id']]);
    }

    public function run(Request $request): JsonResponse
    {
        $spec = $request->validate([
            'image' => ['required', 'string', self::IMAGE],
            'name' => ['nullable', 'string', self::NAME],
            'restart' => ['nullable', 'in:no,always,unless-stopped,on-failure'],
            'ports' => ['array', 'max:20'],
            'ports.*.host' => ['required', 'integer', 'between:1,65535'],
            'ports.*.container' => ['required', 'integer', 'between:1,65535'],
            'ports.*.protocol' => ['nullable', 'in:tcp,udp'],
            'ports.*.public' => ['boolean'],
            'env' => ['array', 'max:100'],
            'env.*.key' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'env.*.value' => ['nullable', 'string', 'max:4096'],
            'volumes' => ['array', 'max:20'],
            'volumes.*.source' => ['required', 'string', 'max:512'],
            'volumes.*.target' => ['required', 'string', 'max:512', 'starts_with:/'],
            'volumes.*.read_only' => ['boolean'],
        ]);
        $spec['name'] = (string) ($spec['name'] ?? '');
        $spec['restart'] = (string) ($spec['restart'] ?? '');
        // drust reads missing fields as defaults but rejects nulls, so every field is filled in.
        $spec['ports'] = array_map(fn ($port) => [
            'host' => (int) $port['host'],
            'container' => (int) $port['container'],
            'protocol' => (string) ($port['protocol'] ?? 'tcp'),
            'public' => (bool) ($port['public'] ?? false),
        ], $spec['ports'] ?? []);
        $spec['env'] = array_map(fn ($env) => ['key' => $env['key'], 'value' => (string) ($env['value'] ?? '')], $spec['env'] ?? []);
        $spec['volumes'] = array_map(fn ($volume) => [
            'source' => $volume['source'],
            'target' => $volume['target'],
            'read_only' => (bool) ($volume['read_only'] ?? false),
        ], $spec['volumes'] ?? []);

        // Variable values can hold secrets, so only their names are logged.
        return $this->act($request, 'run', ['spec' => $spec], [
            'image' => $spec['image'],
            'name' => $spec['name'],
            'ports' => $spec['ports'],
            'env' => array_column($spec['env'], 'key'),
            'volumes' => $spec['volumes'],
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'string', self::NAME],
            'lines' => ['nullable', 'integer', 'between:1,5000'],
        ]);

        try {
            $data = $this->drust->logs($validated['id'], (int) ($validated['lines'] ?? 200));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $this->clean($e)], 422);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function pull(Request $request): JsonResponse
    {
        $image = $request->validate(['image' => ['required', 'string', self::IMAGE]])['image'];

        return $this->act($request, 'pull', ['image' => $image]);
    }

    public function removeImage(Request $request): JsonResponse
    {
        $image = $request->validate(['image' => ['required', 'string', self::IMAGE]])['image'];

        return $this->act($request, 'remove_image', ['image' => $image]);
    }

    public function pruneImages(Request $request): JsonResponse
    {
        return $this->act($request, 'prune_images');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $logged  what goes in the activity log, when not the payload itself
     */
    private function act(Request $request, string $action, array $payload = [], ?array $logged = null): JsonResponse
    {
        try {
            $result = $this->drust->action($action, $payload);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $this->clean($e)], 422);
        }
        try {
            $this->activity->log('docker.'.$action, null, $logged ?? $payload, $request);
        } catch (\Throwable $e) {
            // The change is already live on the server; a missing audit row must not report it as failed.
            report($e);
        }

        return response()->json(['success' => true, 'message' => $result['message'], 'data' => $result['data']]);
    }

    private function clean(\Throwable $e): string
    {
        return (string) preg_replace('/^Failed:\s*/', '', $e->getMessage());
    }
}
