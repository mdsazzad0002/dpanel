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

/** The Docker overview: engine facts, disk use and clean-up. */
class DockerSystemController extends Controller
{
    use CallsDrust;

    public function __construct(
        private readonly DrustDockerClient $drust,
        private readonly ActivityLogService $activity,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Docker/Overview');
    }

    public function templates(): Response
    {
        return Inertia::render('Docker/Templates');
    }

    public function overview(): JsonResponse
    {
        return $this->fetch('system', null, 60);
    }

    /** Clean-up never removes volumes; those go from the Volumes page only. */
    public function action(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:prune,prune_build_cache'],
            'all' => ['boolean'],
        ]);
        $payload = ['action' => $validated['action'], 'all' => (bool) ($validated['all'] ?? false)];

        return $this->change($request, 'system', $payload, 'system.'.$validated['action'], ['all' => $payload['all']], 600);
    }
}
