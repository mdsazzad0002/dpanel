<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Jobs\StartDockerSiteJob;
use App\Models\Website;
use App\Services\ActivityLogService;
use App\Services\Website\DockerSiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Start, stop and inspect the container behind a Docker website, and open or
 * close its port to the internet. Admin-only like the rest of Docker.
 */
class WebsiteDockerController extends Controller
{
    public function __construct(
        private readonly DockerSiteService $sites,
        private readonly ActivityLogService $activity,
    ) {}

    public function control(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $action = $request->validate(['action' => ['required', 'string', 'in:'.implode(',', DockerSiteService::ACTIONS)]])['action'];

        try {
            $data = $this->sites->control($website, $action);
        } catch (\Throwable $e) {
            if (! in_array($action, ['status', 'logs'], true)) {
                $website->forceFill(['docker_process_status' => 'error'])->saveQuietly();
            }

            return response()->json(['success' => false, 'message' => $this->clean($e)], 422);
        }
        if (! in_array($action, ['status', 'logs'], true)) {
            $this->log($request, $website, 'docker_site.'.$action);
        }

        return response()->json([
            'success' => true,
            'message' => match ($action) {
                'start' => 'Container started.',
                'stop' => 'Container stopped.',
                'restart' => 'Container restarted.',
                'recreate' => 'Container recreated with the current settings.',
                default => 'Container status refreshed.',
            },
            'data' => $data,
            'docker_process_status' => $website->fresh()->docker_process_status,
        ]);
    }

    /**
     * Docker can only change a published port by recreating the container,
     * so this saves the choice and recreates it in the background.
     */
    public function updatePublic(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        abort_unless($website->isDockerRuntime(), 422, 'This website does not use the Docker runtime.');
        $public = (bool) $request->validate(['public' => ['required', 'boolean']])['public'];

        $website->forceFill([
            'docker_public' => $public,
            'docker_process_status' => $website->docker_process_status === 'stopped' ? 'stopped' : 'pending',
        ])->saveQuietly();
        if ($website->docker_process_status !== 'stopped') {
            StartDockerSiteJob::dispatch((string) $website->id);
        }
        $this->log($request, $website, $public ? 'docker_site.expose' : 'docker_site.unexpose');

        return response()->json([
            'success' => true,
            'message' => $public
                ? "Port {$website->docker_port} is being opened to the internet."
                : "Port {$website->docker_port} is being closed; the site stays reachable through its domain.",
            'docker_public' => $public,
            'docker_process_status' => $website->docker_process_status,
        ]);
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->findOrFail($id);
    }

    private function log(Request $request, Website $website, string $action): void
    {
        try {
            $this->activity->log($action, $website, ['domain' => $website->domain, 'public' => (bool) $website->docker_public], $request);
        } catch (\Throwable $e) {
            // The change is already live; a missing audit row must not report it as failed.
            report($e);
        }
    }

    private function clean(\Throwable $e): string
    {
        return (string) preg_replace('/^Failed:\s*/', '', $e->getMessage());
    }
}
