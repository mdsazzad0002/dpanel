<?php

namespace App\Http\Controllers\Website;

use App\Http\Requests\Website\AppInstallRequest;
use App\Jobs\AppInstallJob;
use App\Models\Website;
use App\Services\Backup\AppInstallJobStatus;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Ssl\SslLifecycleService;
use App\Services\Website\AppInstallService;
use App\Services\Website\AppPackageUploads;
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
 * Joomla, CodeIgniter and WHMCS installers. Same flow as LaravelInstallerController:
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
        protected AppPackageUploads $uploads,
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
            'databases' => $this->databases->selectable($model, request()->user(), AppInstallService::engines($app)),
            'newDatabase' => $this->databases->preview($model, $app),
            'databaseEngines' => AppInstallService::engines($app),
            'postgresql' => $this->postgresqlAvailability(AppInstallService::engines($app)),
            'adminEmail' => (string) (request()->user()?->email ?? ''),
            'rootInspection' => Inertia::defer(
                fn () => $this->wordpressInstallService->inspectRootDirectory((string) ($website['root_path'] ?? '')),
                'diagnostics',
            ),
        ]);
    }

    /**
     * Whether "create new database" can offer PostgreSQL; only checked for apps that support it.
     *
     * @param  array<int, string>  $engines
     * @return array{installed: bool, active: bool, port: int}
     */
    private function postgresqlAvailability(array $engines): array
    {
        if (! in_array(\App\Models\DatabaseRequest::ENGINE_POSTGRESQL, $engines, true)) {
            return ['installed' => false, 'active' => false, 'port' => (int) config('postgresql.port', 5432)];
        }

        return app(\App\Services\Website\LaravelInstallService::class)->postgresqlAvailability();
    }

    public function install(AppInstallRequest $request, string $token, string $id, string $app): JsonResponse
    {
        $validated = $request->validated();
        $website = Website::query()->findOrFail($this->findAuthorizedWebsiteOrFail($id)['id']);

        $databaseId = $validated['database_id'];
        $databaseIds = array_column($this->databases->selectable($website, $request->user(), AppInstallService::engines($app)), 'id');
        $databaseMode = AppInstallService::APPS[$app]['database'];
        $allowed = $databaseId === 'new'
            || ($databaseId === 'none' && $databaseMode === 'optional')
            || ($databaseMode !== 'new' && in_array($databaseId, $databaseIds, true));
        if (! $allowed) {
            return response()->json(['success' => false, 'message' => 'The selected database is not available.'], 422);
        }

        $installId = (string) Str::uuid();
        AppInstallJobStatus::set($installId, ['stage' => 'queued']);

        AppInstallJob::dispatch($installId, $app, $id, $validated, (int) $request->user()->id);

        return response()->json(['success' => true, 'install_id' => $installId]);
    }

    /**
     * Start a chunked package upload (WHMCS only: its zip needs a customer login to download).
     */
    public function startUpload(Request $request, string $token, string $id, string $app): JsonResponse
    {
        abort_unless(AppInstallService::APPS[$app]['upload'] ?? false, 404);
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\.zip$/i'],
            'size' => ['required', 'integer', 'min:1024', 'max:'.AppPackageUploads::MAX_BYTES],
        ]);

        $uploadId = $this->uploads->init((string) $website['id'], (int) $request->user()->id, (int) $data['size']);

        return response()->json(['success' => true, 'upload_id' => $uploadId]);
    }

    public function uploadChunk(Request $request, string $token, string $id, string $app, string $uploadId): JsonResponse
    {
        abort_unless(AppInstallService::APPS[$app]['upload'] ?? false, 404);
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0', 'max:9999'],
            'chunk' => ['required', 'file', 'max:6144'],
        ]);

        try {
            $this->uploads->storeChunk($uploadId, (string) $website['id'], (int) $request->user()->id, (int) $data['index'], $request->file('chunk')->getRealPath());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => true]);
    }

    public function completeUpload(Request $request, string $token, string $id, string $app, string $uploadId): JsonResponse
    {
        abort_unless(AppInstallService::APPS[$app]['upload'] ?? false, 404);
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $data = $request->validate(['total' => ['required', 'integer', 'between:1,10000']]);

        try {
            $path = $this->uploads->complete($uploadId, (string) $website['id'], (int) $request->user()->id, (int) $data['total']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        if ($this->uploads->whmcsBaseDirectory($path) === null) {
            $this->uploads->delete($uploadId);

            return response()->json(['success' => false, 'message' => 'This is not a WHMCS package: install/bin/installer.php and init.php were not found in the zip.'], 422);
        }

        return response()->json(['success' => true, 'upload_id' => $uploadId, 'size' => filesize($path)]);
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
