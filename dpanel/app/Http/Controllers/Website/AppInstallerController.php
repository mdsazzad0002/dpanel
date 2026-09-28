<?php

namespace App\Http\Controllers\Website;

use App\Http\Requests\Website\AppInstallRequest;
use App\Jobs\AppInstallJob;
use App\Models\Website;
use App\Services\Backup\AppInstallJobStatus;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Ssl\SslLifecycleService;
use App\Services\Website\AppInstallService;
use App\Services\Website\WebsiteCreateEditService;
use App\Services\Website\WebsiteDatabaseProvisioner;
use App\Services\Website\WebsiteResolverService;
use App\Services\Website\WebsiteTemplateCatalogService;
use App\Services\Website\WordpressInstallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Joomla and CodeIgniter installers. Same flow as LaravelInstallerController:
 * the page queues AppInstallJob and polls installStatus().
 */
class AppInstallerController extends WebsiteController
{
    public function __construct(
        WebsiteResolverService $websiteResolver,
        WebsiteTemplateCatalogService $templateCatalog,
        WebsiteCreateEditService $websiteCreateEdit,
        SslLifecycleService $sslLifecycleService,
        FilemanagerService $filemanagerService,
        WordpressInstallService $wordpressInstallService,
        protected AppInstallService $appInstallService,
        protected WebsiteDatabaseProvisioner $databases,
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

    public function installer(string $token, string $id, string $app): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $model = Website::query()->findOrFail($website['id']);

        return Inertia::render('Websites/AppInstaller', [
            'website' => $website,
            'app' => ['key' => $app, ...AppInstallService::APPS[$app]],
            'catalog' => $this->appInstallService->catalog($app, $model),
            'databases' => $this->databases->selectable($model, request()->user()),
            'newDatabase' => $this->databases->preview($model, $app),
            'adminEmail' => (string) (request()->user()?->email ?? ''),
            'rootInspection' => Inertia::defer(
                fn () => $this->wordpressInstallService->inspectRootDirectory((string) ($website['root_path'] ?? '')),
                'diagnostics',
            ),
        ]);
    }

    public function install(AppInstallRequest $request, string $token, string $id, string $app): JsonResponse
    {
        $validated = $request->validated();
        $website = Website::query()->findOrFail($this->findAuthorizedWebsiteOrFail($id)['id']);

        $databaseId = $validated['database_id'];
        $databaseIds = array_column($this->databases->selectable($website, $request->user()), 'id');
        $allowed = $databaseId === 'new'
            || ($databaseId === 'none' && AppInstallService::APPS[$app]['database'] === 'optional')
            || in_array($databaseId, $databaseIds, true);
        if (! $allowed) {
            return response()->json(['success' => false, 'message' => 'The selected database is not available.'], 422);
        }

        $installId = (string) Str::uuid();
        AppInstallJobStatus::set($installId, ['stage' => 'queued']);

        AppInstallJob::dispatch($installId, $app, $id, $validated, (int) $request->user()->id);

        return response()->json(['success' => true, 'install_id' => $installId]);
    }

    public function installStatus(Request $request, string $token, string $id, string $app, string $installId): JsonResponse
    {
        $this->findAuthorizedWebsiteOrFail($id);

        $status = AppInstallJobStatus::get($installId);
        if ($status === null) {
            return response()->json(['success' => false, 'message' => 'Install job not found.'], 404);
        }

        return response()->json(['success' => true, ...$status]);
    }
}
