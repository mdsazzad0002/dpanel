<?php

namespace App\Http\Controllers\Website;

use App\Http\Requests\Website\WordpressInstallRequest;
use App\Jobs\WordpressInstallJob;
use App\Models\Website;
use App\Services\Backup\WordpressInstallJobStatus;
use App\Services\Filemanager\FilemanagerService;
use App\Services\Ssl\SslLifecycleService;
use App\Services\Website\WebsiteCreateEditService;
use App\Services\Website\WebsiteResolverService;
use App\Services\Website\WebsiteTemplateCatalogService;
use App\Services\Website\WordpressInstallService;
use App\Services\Website\WordpressSsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WordpressController extends WebsiteController
{
    public function __construct(
        WebsiteResolverService $websiteResolver,
        WebsiteTemplateCatalogService $templateCatalog,
        WebsiteCreateEditService $websiteCreateEdit,
        SslLifecycleService $sslLifecycleService,
        FilemanagerService $filemanagerService,
        WordpressInstallService $wordpressInstallService,
        protected WordpressSsoService $wordpressSsoService,
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

    public function wordpressManager(string $token, string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        return Inertia::render('Websites/WordPressInstaller', [
            'website' => $website,
            'rootInspection' => Inertia::defer(
                fn () => $this->wordpressInstallService->inspectRootDirectory(
                    $this->wordpressInstallService->resolveInstallationRoot($website),
                ),
                'diagnostics',
            ),
            'wordpressVersions' => Inertia::defer(
                fn () => $this->wordpressInstallService->getWordPressVersionOptions(),
                'remote',
            ),
        ]);
    }

    /**
     * Dispatches the install to the queue instead of running it inline, so
     * the frontend can poll installStatus() for a step-by-step progress bar
     * (downloading → creating database → connecting database) rather than
     * blocking on one long request.
     */
    public function installWordPress(WordpressInstallRequest $request, string $token, string $id): JsonResponse
    {
        $validated = $request->validated();
        $this->findAuthorizedWebsiteOrFail($id);

        $installId = (string) Str::uuid();
        WordpressInstallJobStatus::set($installId, ['stage' => 'queued']);

        WordpressInstallJob::dispatch($installId, $id, $validated, (int) $request->user()->id);

        return response()->json(['success' => true, 'install_id' => $installId]);
    }

    public function installStatus(Request $request, string $token, string $id, string $installId): JsonResponse
    {
        $this->findAuthorizedWebsiteOrFail($id);

        $status = WordpressInstallJobStatus::get($installId);
        if ($status === null) {
            return response()->json(['success' => false, 'message' => 'Install job not found.'], 404);
        }

        return response()->json(['success' => true, ...$status]);
    }

    /**
     * Issues a one-time WordPress auto-login link (see WordpressSsoService)
     * so the panel user can jump into wp-admin without WordPress credentials.
     */
    public function wordpressSsoLogin(string $token, string $id): JsonResponse
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);

        try {
            $url = $this->wordpressSsoService->generateLoginUrl($website);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'url' => $url]);
    }
}
