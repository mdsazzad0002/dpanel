<?php

namespace App\Services\Website;

use App\Services\Filemanager\FilemanagerService;
use Illuminate\Support\Facades\Http;

class ProjectDependencyService
{
    private const VITE_CONFIGS = ['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.cjs', 'vite.config.mts'];

    public function __construct(private readonly FilemanagerService $filemanager) {}

    /**
     * Run composer_install, npm_install (install + build) or npm_build as the site owner.
     *
     * @return array{success: bool, output: string}
     */
    public function run(string $siteOwner, string $projectRoot, string $action): array
    {
        $client = Http::acceptJson()->asJson()->timeout(900);
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($apiToken !== '') {
            $client = $client->withToken($apiToken);
        }

        try {
            $response = $client->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/project-dependencies', [
                'site_owner' => $siteOwner,
                'project_root' => rtrim($projectRoot, '/'),
                'action' => $action,
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        }
        $json = $response->json();

        return [
            'success' => $response->successful() && (bool) ($json['success'] ?? false),
            'output' => (string) ($json['message'] ?? ''),
        ];
    }

    /**
     * For a Node project that uses Vite: remove a stale public/hot (it makes
     * Laravel load assets from a dev server that isn't running) and build the
     * assets when public/build/manifest.json is missing.
     *
     * @return array{vite: bool, hot_removed: bool, built: bool, error: ?string}
     */
    public function ensureViteAssets(string $siteOwner, string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/');
        $result = ['vite' => false, 'hot_removed' => false, 'built' => false, 'error' => null];

        if (! $this->filemanager->fileExists($root.'/package.json')) {
            return $result;
        }
        $usesVite = false;
        foreach (self::VITE_CONFIGS as $config) {
            if ($this->filemanager->fileExists($root.'/'.$config)) {
                $usesVite = true;
                break;
            }
        }
        if (! $usesVite) {
            return $result;
        }
        $result['vite'] = true;

        if ($this->filemanager->fileExists($root.'/public/hot')) {
            $this->filemanager->deletePath($siteOwner, $root.'/public/hot');
            $result['hot_removed'] = true;
        }

        if (! $this->filemanager->fileExists($root.'/public/build/manifest.json')) {
            $action = $this->filemanager->directoryExists($root.'/node_modules') ? 'npm_build' : 'npm_install';
            $build = $this->run($siteOwner, $root, $action);
            $result['built'] = $build['success'];
            if (! $build['success']) {
                $result['error'] = $build['output'] !== '' ? $build['output'] : 'Vite build failed.';
            }
        }

        return $result;
    }
}
