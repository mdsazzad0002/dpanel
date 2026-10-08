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

/** Named Docker volumes: where containers keep data across recreates. */
class DockerVolumeController extends Controller
{
    use CallsDrust;

    public function __construct(
        private readonly DrustDockerClient $drust,
        private readonly ActivityLogService $activity,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Docker/Volumes');
    }

    public function list(): JsonResponse
    {
        // Sizes come from walking every volume, which can take a while on a big server.
        return $this->fetch('volumes', null, 180);
    }

    public function action(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:create,remove,prune'],
            'name' => ['required_unless:action,prune', 'nullable', 'string', RunSpecInput::NAME],
            'all' => ['boolean'],
        ]);
        $payload = [
            'action' => $validated['action'],
            'name' => (string) ($validated['name'] ?? ''),
            'all' => (bool) ($validated['all'] ?? false),
        ];

        return $this->change($request, 'volumes', $payload, 'volume.'.$validated['action'], array_filter(['name' => $payload['name'], 'all' => $payload['all']]), 300);
    }
}
