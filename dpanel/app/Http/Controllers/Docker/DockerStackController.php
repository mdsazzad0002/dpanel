<?php

namespace App\Http\Controllers\Docker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Docker\Concerns\CallsDrust;
use App\Services\ActivityLogService;
use App\Services\Docker\DrustDockerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compose stacks: a docker-compose file run as one unit. drust checks each
 * file with `docker compose config` before it replaces a working one.
 */
class DockerStackController extends Controller
{
    use CallsDrust;

    public const NAME = 'regex:/^[a-z0-9][a-z0-9_-]{0,62}$/';

    private const SERVICE = 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/';

    /** Actions that may pull images first, which takes minutes. */
    private const SLOW = ['deploy', 'deploy_new', 'up', 'pull', 'update'];

    public function __construct(
        private readonly DrustDockerClient $drust,
        private readonly ActivityLogService $activity,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Docker/Stacks');
    }

    public function list(): JsonResponse
    {
        return $this->fetch('stacks');
    }

    public function show(Request $request): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', self::NAME]])['name'];

        return $this->fetch('stacks/show', ['name' => $name]);
    }

    public function action(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:create,save,deploy,deploy_new,up,pull,update,start,stop,restart,down,remove'],
            'name' => ['required', 'string', self::NAME],
            'service' => ['nullable', 'string', self::SERVICE],
            'compose' => ['required_if:action,create,save,deploy,deploy_new', 'nullable', 'string', 'max:262144'],
            'env' => ['nullable', 'string', 'max:65536'],
            'remove_volumes' => ['boolean'],
        ]);
        $payload = [
            'action' => $validated['action'],
            'name' => $validated['name'],
            'service' => (string) ($validated['service'] ?? ''),
            'compose' => (string) ($validated['compose'] ?? ''),
            'env' => (string) ($validated['env'] ?? ''),
            'remove_volumes' => (bool) ($validated['remove_volumes'] ?? false),
        ];
        $timeout = in_array($payload['action'], self::SLOW, true) ? 900 : 300;

        // The files can hold passwords, so only what was done to which stack is logged.
        return $this->change($request, 'stacks', $payload, 'stack.'.$payload['action'], array_filter([
            'name' => $payload['name'],
            'service' => $payload['service'],
            'remove_volumes' => $payload['remove_volumes'],
        ]), $timeout);
    }

    public function logs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', self::NAME],
            'service' => ['nullable', 'string', self::SERVICE],
            'lines' => ['nullable', 'integer', 'between:1,5000'],
        ]);

        return $this->fetch('stacks/logs', [
            'name' => $validated['name'],
            'service' => (string) ($validated['service'] ?? ''),
            'lines' => (int) ($validated['lines'] ?? 200),
        ], 60);
    }
}
