<?php

namespace App\Http\Controllers\Docker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Docker\Concerns\CallsDrust;
use App\Services\ActivityLogService;
use App\Services\Docker\DrustDockerClient;
use App\Services\Docker\RunSpecInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Docker networks: containers on the same one reach each other by name,
 * e.g. Kibana → http://elasticsearch:9200. Admin-only like all of Docker.
 */
class DockerNetworkController extends Controller
{
    use CallsDrust;

    public function __construct(
        private readonly DrustDockerClient $drust,
        private readonly ActivityLogService $activity,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Docker/Networks');
    }

    public function list(): JsonResponse
    {
        return $this->fetch('networks');
    }

    public function action(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:create,remove,connect,disconnect,prune'],
            'name' => ['required_unless:action,prune', 'nullable', 'string', RunSpecInput::NAME],
            'container' => ['required_if:action,connect,disconnect', 'nullable', 'string', RunSpecInput::NAME],
            'aliases' => ['array', 'max:10'],
            'aliases.*' => ['nullable', 'string', RunSpecInput::NAME],
            'internal' => ['boolean'],
            'subnet' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
        ]);
        $name = (string) ($validated['name'] ?? '');
        $payload = [
            'action' => $validated['action'],
            'name' => $name,
            'container' => (string) ($validated['container'] ?? ''),
            'aliases' => array_values(array_filter(array_map('strval', $validated['aliases'] ?? []), fn ($a) => trim($a) !== '')),
        ];
        if ($validated['action'] === 'create') {
            $payload['spec'] = [
                'name' => $name,
                'internal' => (bool) ($validated['internal'] ?? false),
                'subnet' => (string) ($validated['subnet'] ?? ''),
            ];
        }
        $logged = array_filter(['name' => $name, 'container' => $payload['container'], 'aliases' => $payload['aliases'], 'spec' => $payload['spec'] ?? null]);

        return $this->change($request, 'networks', $payload, 'network.'.$validated['action'], $logged);
    }
}
