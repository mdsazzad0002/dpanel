<?php

namespace App\Http\Controllers\Website;

use App\Http\Requests\Website\WordpressInstallRequest;
use App\Services\Website\WebsiteResolverService;
use App\Services\Website\WordpressInstallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WordpressController extends WebsiteController
{
    public function __construct(
        WebsiteResolverService $websiteResolver,
        protected WordpressInstallService $wordpressInstallService,
    ) {
        parent::__construct($websiteResolver);
    }

    public function wordpressManager(string $token, string $id): Response
    {
        $website = $this->findAuthorizedWebsiteOrFail($id);
        $rootInspection = $this->wordpressInstallService->inspectRootDirectory((string) ($website['root_path'] ?? ''));

        return Inertia::render('Websites/WordPressInstaller', [
            'website' => $website,
            'wordpressVersions' => $this->wordpressInstallService->getWordPressVersionOptions(),
            'rootInspection' => $rootInspection,
        ]);
    }

    public function installWordPress(WordpressInstallRequest $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $returnToWordPress = (string) ($validated['return_to'] ?? '') === 'wordpress';

        $redirectTarget = function () use ($id, $returnToWordPress): RedirectResponse {
            if ($returnToWordPress) {
                return redirect()->route('websites.wordpress.manager', $id);
            }

            return redirect()->route('websites.manage', $id);
        };

        $fail = function (string $message) use ($request, $redirectTarget): RedirectResponse|JsonResponse {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 422);
            }

            return $redirectTarget()->with('error', $message);
        };

        $succeed = function (string $message, array $payload = []) use ($request, $redirectTarget): RedirectResponse|JsonResponse {
            if ($request->expectsJson()) {
                return response()->json(array_merge([
                    'success' => true,
                    'message' => $message,
                ], $payload));
            }

            return $redirectTarget()->with('success', $message);
        };

        $website = $this->findAuthorizedWebsiteOrFail($id);
        $result = $this->wordpressInstallService->install($website, $validated, $request->user());

        if (! ($result['success'] ?? false)) {
            return $fail((string) ($result['message'] ?? 'WordPress installation failed.'));
        }

        $updatedWebsite = (array) ($result['website'] ?? $website);
        $requests = collect($this->readRequests());
        $updated = $requests->map(function (array $item) use ($id, $updatedWebsite): array {
            if ((string) ($item['id'] ?? '') !== $id) {
                return $item;
            }

            $item['app_installer'] = (string) ($updatedWebsite['app_installer'] ?? 'wordpress');
            $item['wordpress_version'] = (string) ($updatedWebsite['wordpress_version'] ?? 'latest');
            $item['wordpress_db_prefix'] = (string) ($updatedWebsite['wordpress_db_prefix'] ?? '');
            $item['status'] = (string) ($updatedWebsite['status'] ?? ($item['status'] ?? 'pending'));
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();
        $this->writeRequests($updated);

        $domain = (string) ($updatedWebsite['domain'] ?? '');
        $rootPath = (string) ($updatedWebsite['root_path'] ?? '');
        $phpVersion = (string) ($updatedWebsite['php_version'] ?? '8.0');
        if ($domain !== '' && $rootPath !== '') {
            $this->relocateApacheDefaultPage();
            $this->syncLiveWebVhost($domain, $rootPath, $phpVersion);
        }

        return $succeed((string) ($result['message'] ?? 'WordPress installed successfully.'), [
            'website' => $this->findAuthorizedWebsiteOrFail($id),
            'database_request' => $result['database_request'] ?? null,
        ]);
    }
}
