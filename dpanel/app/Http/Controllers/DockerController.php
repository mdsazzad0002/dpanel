<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Docker\Concerns\CallsDrust;
use App\Services\ActivityLogService;
use App\Services\Docker\DrustDockerClient;
use App\Services\Docker\RunSpecInput;
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
    use CallsDrust;

    private const NAME = RunSpecInput::NAME;

    private const IMAGE = RunSpecInput::IMAGE;

    private const CONTAINER_ACTIONS = ['start', 'stop', 'restart', 'remove', 'pause', 'unpause', 'kill', 'update'];

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
            'action' => ['required', 'in:'.implode(',', self::CONTAINER_ACTIONS)],
            'id' => ['required', 'string', self::NAME],
        ]);

        return $this->act($request, $validated['action'], ['id' => $validated['id']]);
    }

    /**
     * The same action on several containers, one after another; one failure
     * does not stop the rest, and the answer says which ones failed.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:start,stop,restart,remove,pause,unpause'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', self::NAME],
        ]);

        $failed = [];
        $data = null;
        foreach (array_unique($validated['ids']) as $id) {
            try {
                $data = $this->drust->action($validated['action'], ['id' => $id])['data'];
            } catch (\Throwable $e) {
                $failed[] = $id.': '.$this->clean($e);
            }
        }
        $done = count(array_unique($validated['ids'])) - count($failed);
        if ($done > 0) {
            $this->audit($request, 'bulk_'.$validated['action'], ['ids' => array_values(array_unique($validated['ids'])), 'failed' => count($failed)]);
        }
        try {
            $data ??= $this->drust->status();
        } catch (\Throwable) {
            $data = null;
        }

        $message = "{$done} container(s) done.".($failed ? "\nFailed:\n".implode("\n", $failed) : '');

        return response()->json(['success' => $failed === [], 'message' => $message, 'data' => $data], $failed && $done === 0 ? 422 : 200);
    }

    public function rename(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'string', self::NAME],
            'name' => ['required', 'string', self::NAME],
        ]);

        return $this->act($request, 'rename', $validated);
    }

    public function run(Request $request): JsonResponse
    {
        $spec = RunSpecInput::normalize($request->validate(RunSpecInput::rules()));

        return $this->act($request, 'run', ['spec' => $spec], RunSpecInput::forLog($spec));
    }

    /** Replaces a container with one made from changed settings; the old one comes back if the new one fails. */
    public function recreate(Request $request): JsonResponse
    {
        $validated = $request->validate(['id' => ['required', 'string', self::NAME]] + RunSpecInput::rules('spec'));
        $spec = RunSpecInput::normalize($validated['spec']);

        return $this->act($request, 'recreate', ['id' => $validated['id'], 'spec' => $spec], ['id' => $validated['id']] + RunSpecInput::forLog($spec));
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

    /** Settings, state, mounts and networks of one container, plus the spec that recreates it. */
    public function inspect(Request $request): JsonResponse
    {
        $id = $request->validate(['id' => ['required', 'string', self::NAME]])['id'];

        return $this->fetch('inspect', ['id' => $id]);
    }

    public function stats(): JsonResponse
    {
        return $this->fetch('stats', null, 60);
    }

    /** One command inside a running container, killed after a minute. */
    public function exec(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'string', self::NAME],
            'command' => ['required', 'string', 'max:8192'],
            'user' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.:-]*$/'],
            'workdir' => ['nullable', 'string', 'max:512', 'starts_with:/'],
        ]);
        $payload = [
            'id' => $validated['id'],
            'command' => $validated['command'],
            'user' => (string) ($validated['user'] ?? ''),
            'workdir' => (string) ($validated['workdir'] ?? ''),
        ];

        // The command is the audit trail here, so it is logged (shortened).
        return $this->change($request, 'exec', $payload, 'exec', [
            'id' => $payload['id'],
            'command' => mb_substr($payload['command'], 0, 500),
            'user' => $payload['user'],
        ], 90);
    }

    public function pruneContainers(Request $request): JsonResponse
    {
        return $this->act($request, 'prune_containers');
    }

    public function pull(Request $request): JsonResponse
    {
        $image = $request->validate(['image' => ['required', 'string', self::IMAGE]])['image'];

        return $this->act($request, 'pull', ['image' => $image]);
    }

    public function removeImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'string', self::IMAGE],
            'force' => ['boolean'],
        ]);

        return $this->act($request, 'remove_image', ['image' => $validated['image'], 'force' => (bool) ($validated['force'] ?? false)]);
    }

    public function pruneImages(Request $request): JsonResponse
    {
        $all = (bool) ($request->validate(['all' => ['boolean']])['all'] ?? false);

        return $this->act($request, 'prune_images', ['all' => $all]);
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
        $this->audit($request, $action, $logged ?? $payload);

        return response()->json(['success' => true, 'message' => $result['message'], 'data' => $result['data']]);
    }
}
