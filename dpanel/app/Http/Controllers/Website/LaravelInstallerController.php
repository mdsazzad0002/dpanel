<?php

namespace App\Http\Controllers\Website;

use App\Http\Requests\Website\LaravelInstallRequest;
use App\Jobs\LaravelInstallJob;
use App\Models\Website;
use App\Models\WebsiteGitDeployment;
use App\Services\Backup\LaravelInstallJobStatus;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Ssl\SslLifecycleService;
use App\Services\Website\LaravelInstallService;
use App\Services\Website\WebsiteCreateEditService;
use App\Services\Website\WebsiteResolverService;
use App\Services\Website\WebsiteTemplateCatalogService;
use App\Services\Website\WordpressInstallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LaravelInstallerController extends WebsiteController
{
    public function __construct(
        WebsiteResolverService $websiteResolver,
        WebsiteTemplateCatalogService $templateCatalog,
        WebsiteCreateEditService $websiteCreateEdit,
        SslLifecycleService $sslLifecycleService,
        FilemanagerService $filemanagerService,
        WordpressInstallService $wordpressInstallService,
        protected LaravelInstallService $laravelInstallService,
    ) {
        parent::__construct(
            $websiteResolver,
            $templateCatalog,
            $websiteCreateEdit,
            $sslLifecycleService,
            $filemanagerService,
            $wordpressInstallService,
        );
    }

    public function laravelInstaller(string $token, string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $model = Website::query()->findOrFail($website['id']);
        $databases = $this->laravelInstallService->selectableDatabases($model, request()->user());

        return Inertia::render('Websites/LaravelInstaller', [
            'website' => $website,
            'catalog' => $this->laravelInstallService->catalog($model),
            'databases' => $databases,
            'newDatabase' => $this->laravelInstallService->previewNewDatabase($model),
            'postgresql' => $this->laravelInstallService->postgresqlAvailability(),
            'databaseEngines' => LaravelInstallService::DATABASE_ENGINES,
            'gitRepository' => WebsiteGitDeployment::query()->where('website_id', $model->id)->first()?->only(['repository_full_name', 'repository_url', 'branch']),
            'rootInspection' => Inertia::defer(
                fn () => $this->wordpressInstallService->inspectRootDirectory((string) ($website['root_path'] ?? '')),
                'diagnostics',
            ),
        ]);
    }

    /**
     * Queues the install; the page polls laravelInstallStatus() for per-step progress since
     * composer + the frontend build can run for several minutes.
     */
    public function installLaravel(LaravelInstallRequest $request, string $token, string $id): JsonResponse
    {
        $validated = $request->validated();
        $website = Website::query()->findOrFail($this->findAuthorizedWebsiteOrFail($id)['id']);

        if (! in_array($validated['laravel_version'], LaravelInstallService::STACKS[$validated['stack']]['versions'], true)) {
            return response()->json(['success' => false, 'message' => 'This stack is not available for the selected Laravel version.'], 422);
        }
        $databaseIds = array_column($this->laravelInstallService->selectableDatabases($website, $request->user()), 'id');
        if ($validated['database_id'] !== 'new' && ! in_array($validated['database_id'], $databaseIds, true)) {
            return response()->json(['success' => false, 'message' => 'The selected database is not available.'], 422);
        }

        $installId = (string) Str::uuid();
        LaravelInstallJobStatus::set($installId, ['stage' => 'queued']);

        LaravelInstallJob::dispatch($installId, $id, $validated, (int) $request->user()->id);

        return response()->json(['success' => true, 'install_id' => $installId]);
    }

    public function laravelInstallStatus(Request $request, string $token, string $id, string $installId): JsonResponse
    {
        $this->findAuthorizedWebsiteOrFail($id);

        $status = LaravelInstallJobStatus::get($installId);
        if ($status === null) {
            return response()->json(['success' => false, 'message' => 'Install job not found.'], 404);
        }

        return response()->json(['success' => true, ...$status]);
    }
}
