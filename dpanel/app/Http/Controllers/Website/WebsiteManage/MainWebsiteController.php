<?php

namespace App\Http\Controllers\Website\WebsiteManage;

use App\Http\Controllers\Controller;
use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Models\Website;
use App\Models\User;
use App\Models\WebsiteTrashBackup;
use App\Services\Cron\CronSystemService;
use App\Services\Dns\WebsiteDnsProvisionService;
use App\Services\Filemanager\FilemanagerService;
use App\Services\PathService;
use App\Services\Php\PhpService;
use App\Services\Ssl\SslLifecycleService;
use App\Services\Website\WebsiteService;
use App\Services\Website\WebsiteTrashService;
use App\Services\ResourceQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class MainWebsiteController extends Controller
{
    public function __construct(
        protected FilemanagerService $filemanagerService,
        protected WebsiteService $websiteService,
        protected PathService $paths,
        protected WebsiteTrashService $websiteTrashService,
        protected SslLifecycleService $sslLifecycleService,
        protected CronSystemService $cronSystemService,
        protected WebsiteDnsProvisionService $websiteDnsProvisionService,
        protected ResourceQuotaService $quotas,
    ) {
    }

    /**
     * Show website creation page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Websites/Create', [
            'serverBaseDir' => PathService::websiteBaseDirectory(),
            'phpVersions' => PhpService::getPhpVersions(),
            'domainUsers' => User::query()
                ->when($request->user()?->hasRole('reseller'), fn ($query) => $query->where('reseller_id', $request->user()->id))
                ->whereHas('roles', fn ($query) => $query->whereIn('name', ['general', 'general_user']))
                ->orderBy('name')->get(['id', 'name', 'email', 'package_id'])]
        );
    }



        /**
     * Create a website command request.
     * Command execution is intentionally commented out.
     */
    public function store(Request $request): JsonResponse
    {
        $domainRegex = '/^(?=.{1,253}$)(?!-)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/';
        $domainType = (string) $request->input('domain_type', 'main');

        $validator = Validator::make($request->all(), [
            'domain' => [
                'required',
                'string',
                'max:255',
                "regex:{$domainRegex}",
            ],
            'parent_domain' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($domainRegex): void {
                    $normalized = strtolower(trim((string) $value));

                    if ($normalized !== '' && preg_match($domainRegex, $normalized) !== 1) {
                        $fail("The {$attribute} must be a valid domain name.");
                    }
                },
            ],
            'parent_id' => ['nullable', 'string', 'max:64'],
            'subdomain_prefix' => [
                'nullable',
                'string',
                'max:63',
                function (string $attribute, mixed $value, \Closure $fail) use ($domainType): void {
                    $normalized = strtolower(trim((string) $value));

                    if ($domainType === 'sub' && $normalized === '') {
                        $fail("The {$attribute} field is required for subdomains.");
                        return;
                    }

                    if ($normalized !== '' && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $normalized) !== 1) {
                        $fail("The {$attribute} format is invalid.");
                    }
                },
            ],
            'root_path' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($domainType): void {
                    if ($domainType === 'alis') {
                        return;
                    }

                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    if (! $this->paths->pathStartsWith($this->paths->normalizeAbsolutePathValue($value), $this->paths->websiteBaseDirectoryValue().'/')) {
                        $fail("The {$attribute} must be inside ".$this->paths->websiteBaseDirectoryValue().' and follow <base>/<owner>/<site_dir>.');
                    }
                },
            ],
            'start_directory' => ['nullable', 'string', 'max:255'],
            'php_version' => [$domainType === 'alis' ? 'nullable' : 'required', 'string', 'max:10'],
            'domain_type' => ['required', 'string', 'in:main,alis,sub'],
            'enable_ssl' => ['boolean'],
            'manage_dns' => ['boolean'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'type' => 'error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $assignedUser = ! empty($validated['assigned_user_id'])
            ? User::query()->find((int) $validated['assigned_user_id'])
            : null;
        if ($assignedUser !== null && $request->user()?->hasRole('reseller')
            && (int) $assignedUser->reseller_id !== (int) $request->user()->id) {
            abort(403);
        }
        if ($assignedUser !== null) {
            $this->quotas->assertWebsiteAllowed($assignedUser, $domainType === 'alis');
        }

        $parentWebsite = null;
        if ($validated['domain_type'] === 'alis') {
            $parentWebsite = Website::query()->visibleTo($request->user())
                ->whereKey((string) ($validated['parent_id'] ?? ''))
                ->first();
            if ($parentWebsite === null) {
                return response()->json(['type' => 'error', 'message' => 'Select a valid parent website for this alias.', 'errors' => ['parent_id' => ['The selected parent website is invalid.']]], 422);
            }

            if ($parentWebsite->assigned_user_id) {
                $assignedUser = User::query()->find($parentWebsite->assigned_user_id);
                if ($assignedUser !== null) {
                    $this->quotas->assertWebsiteAllowed($assignedUser, true);
                }
            }

            $validated['parent_domain'] = (string) $parentWebsite->domain;
        }



        // Exist Check
        if (WebsiteService::existWebsite($validated['domain'])) {
            return response()->json([
                'type' => 'error',
                'message' => 'Website Already Exists',
            ], 422);
        }



        $siteOwnerSource = $validated['domain_type'] === 'sub'
            ? (string) ($validated['parent_domain'] ?? '')
            : (string) $validated['domain'];
        $siteOwner = $this->paths->normalizeSiteOwnerFromDomain($siteOwnerSource);
        $siteDirectory = $validated['domain_type'] === 'sub'
            ? $this->paths->normalizeSiteDirectory((string) ($validated['subdomain_prefix'] ?? ''), 'blog')
            : 'public_html';

        if ($parentWebsite !== null) {
            $siteOwner = (string) $parentWebsite->site_owner;
            $projectRoot = (string) $parentWebsite->project_root;
            $rootPath = (string) $parentWebsite->root_path;
            $startDirectory = $parentWebsite->start_directory;
            $phpVersion = (string) $parentWebsite->php_version;
            $demoFiles = [];
        } else {
            $homeSetup = $this->filemanagerService->createAccountHome($siteOwner, null, '/bin/bash', $siteDirectory);
            $projectRoot = $homeSetup['project_root'];
            $rootPath = $homeSetup['root_path'] ?? $homeSetup['public_html'];
            $startDirectory = array_key_exists('start_directory', $validated) ? trim((string) $validated['start_directory']) : null;
            $phpVersion = (string) $validated['php_version'];
        }

        if ($parentWebsite === null && (! is_dir($projectRoot) || ! is_dir($rootPath))) {
            return response()->json([
                'type' => 'error',
                'message' => 'Failed to prepare website account home.',
                'errors' => [
                    'project_root' => is_dir($projectRoot) ? null : 'Project root is missing after account setup.',
                    'root_path' => is_dir($rootPath) ? null : 'Website root path is missing after account setup.',
                ],
            ], 422);
        }


        // Folder Check
        $folderCheck = $parentWebsite === null ? $this->filemanagerService->ensureWebsiteFoldersExist(
            $request,
            $rootPath,
            $projectRoot,
            'create',
            true
        ) : null;
        if ($folderCheck instanceof JsonResponse) {
            return $folderCheck;
        }




        // Demo page setup
        try {
            if ($parentWebsite === null) {
                if ($startDirectory === '') $startDirectory = null;
                $demoFiles = $this->websiteService->createDemoSitePage(
                $rootPath,
                (string) $validated['domain'],
                $phpVersion,
                $startDirectory,
                $siteOwner,
                );
            }
        } catch (\Throwable $e) {
            return response()->json([
                'type' => 'error',
                'message' => 'Website demo page setup failed.',
                'errors' => [
                    'demo_site' => $e->getMessage(),
                ],
            ], 422);
        }




        // Store in database
        $website = Website::query()->create([
            'id' => (string) str()->uuid(),
            'domain' => (string) $validated['domain'],
            'hostname' => (string) $validated['domain'],
            'parent_id' => $parentWebsite?->id,
            'scope' => 'user',
            'root_path' => $rootPath,
            'project_root' => $projectRoot,
            'start_directory' => $startDirectory,
            'site_owner' => $siteOwner,
            'php_version' => $phpVersion,
            'enable_ssl' => $parentWebsite?->enable_ssl ?? (bool) ($validated['enable_ssl'] ?? false),
            'manage_dns' => (bool) ($validated['manage_dns'] ?? false),
            'filemanager_show_hidden' => false,
            'assigned_user_id' => $parentWebsite?->assigned_user_id ?? $assignedUser?->id,
            'assigned_reseller_id' => ($request->user()?->hasRole('reseller') ? (int) $request->user()->id : null),
            'status' => 'live',
            'type' => match ((string) $validated['domain_type']) {
                'alis' => 'alias',
                'sub' => 'subdomain',
                default => 'primary',
            },
            'ssl_mode' => $parentWebsite?->ssl_mode ?? (!empty($validated['enable_ssl']) ? 'letsencrypt' : 'none'),
        ]);
        $activation = ['managed_by' => 'edge-network'];
        $sslResult = ['status' => 'disabled'];
        if ($website->enable_ssl) {
            try {
                $sslResult = $this->sslLifecycleService->ensureForWebsite($website->fresh());
            } catch (\Throwable $e) {
                $website->forceFill([
                    'status' => 'live',
                    'ssl_mode' => 'none',
                ])->saveQuietly();

                return response()->json([
                    'type' => 'success',
                    'message' => 'Website created and available over HTTP. SSL status: invalid. '.$e->getMessage(),
                    'website' => $website->fresh(),
                    'gateway_activation' => $activation,
                    'ssl' => ['status' => 'failed', 'error' => $e->getMessage()],
                ], 422);
            }
        }

        $dnsResult = ['managed' => false, 'created' => false, 'reactivated' => false, 'skipped' => 'not-requested', 'nameservers' => []];
        try {
            $dnsResult = $this->websiteDnsProvisionService->syncWebsite($website->fresh(), (bool) $website->manage_dns);
            if (($dnsResult['skipped'] ?? null) === 'external-nameservers') {
                $website->forceFill(['manage_dns' => false])->saveQuietly();
            }
        } catch (\Throwable $e) {
            Log::warning('Website DNS provisioning failed', [
                'website_id' => $website->id,
                'domain' => $website->domain,
                'error' => $e->getMessage(),
            ]);
        }
        $message = 'Website created successfully.';

        return response()->json([
            'type' => 'success',
            'message' => $message.' Demo site files created successfully.',
            'demo_files' => $demoFiles,
            'gateway_activation' => $activation,
            'ssl' => $sslResult,
            'dns' => $dnsResult,
            'website' => $website->fresh(),
        ]);
    }

    /**
     * Destroy a website and archive its files for restore.
     */
    public function destroy(Request $request, string $token, string $id): RedirectResponse|JsonResponse
    {
        $website = Website::query()
            ->visibleTo($request->user())
            ->firstWhere('id', $id);

        abort_if($website === null, 404);

        $domain = strtolower(trim((string) ($website->domain ?? '')));
        $websiteType = strtolower(trim((string) ($website->type ?? '')));
        if (! in_array($websiteType, ['primary', 'main'], true)) {
            return $this->destroyLinkedDomain($request, $website, $domain);
        }

        $databaseRequests = DatabaseRequest::query()
            ->where('domain', $domain)
            ->get(['id', 'domain', 'database_name', 'database_user', 'database_host', 'charset', 'collation', 'status', 'assigned_user_id'])
            ->map(fn (DatabaseRequest $item): array => [
                'id' => (string) $item->id,
                'domain' => (string) $item->domain,
                'database_name' => (string) $item->database_name,
                'database_user' => (string) $item->database_user,
                'database_host' => (string) $item->database_host,
                'charset' => (string) $item->charset,
                'collation' => (string) $item->collation,
                'status' => (string) $item->status,
                'assigned_user_id' => $item->assigned_user_id,
            ])
            ->all();
        $cronJobModels = CronJob::query()
            ->where('website_id', $id)
            ->get(['id', 'website_id', 'domain', 'name', 'expression', 'command', 'status', 'description']);
        $cronJobs = $cronJobModels->map(fn (CronJob $job): array => [
            'id' => (string) $job->id,
            'website_id' => (string) $job->website_id,
            'domain' => (string) ($job->domain ?? ''),
            'name' => (string) ($job->name ?? ''),
            'expression' => (string) ($job->expression ?? ''),
            'command' => (string) ($job->command ?? ''),
            'status' => (string) ($job->status ?? ''),
            'description' => (string) ($job->description ?? ''),
        ])
            ->all();
        $cleanupMessages = [];

        try {
            $archive = $this->archiveWebsiteViaDrust($website, $databaseRequests, $cronJobs);
            $cleanupMessages[] = 'Website files archived to '.$archive['zip_name'].'.';
        } catch (\Throwable $e) {
            Log::error('Website trash archive failed', [
                'website_id' => $id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return $this->websiteDestroyError($request, 'Website archive failed.', 'archive', $e);
        }

        $removedCronJobs = collect();
        try {
            foreach ($cronJobModels as $cronJob) {
                $this->cronSystemService->delete((string) $cronJob->id);
                $removedCronJobs->push($cronJob);
            }
        } catch (\Throwable $e) {
            $this->restoreSystemCronJobs($removedCronJobs, $website);
            Log::error('Website system cron cleanup failed', [
                'website_id' => $id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return $this->websiteDestroyError($request, 'Website cron cleanup failed.', 'cron_jobs', $e);
        }

        try {
            $ownerIsShared = Website::query()
                ->where('site_owner', (string) $website->site_owner)
                ->whereKeyNot($id)
                ->exists();
            $this->deleteWebsiteViaDrust($website, ! $ownerIsShared);
            $cleanupMessages[] = $ownerIsShared
                ? 'Website directory removed.'
                : 'Website directory and system user removed.';
        } catch (\Throwable $e) {
            $this->restoreSystemCronJobs($removedCronJobs, $website);

            return $this->websiteDestroyError($request, 'Website system cleanup failed.', 'system_cleanup', $e);
        }

        try {
            DB::transaction(function () use ($request, $website, $domain, $id, $archive, $databaseRequests, $cronJobs): void {
                WebsiteTrashBackup::query()->create([
                    'id' => (string) str()->uuid(),
                    'website_id' => (string) $website->id,
                    'domain' => $domain,
                    'file_name' => (string) $archive['zip_name'],
                    'file_path' => (string) $archive['zip_path'],
                    'file_size' => (int) (@filesize((string) $archive['zip_path']) ?: 0),
                    'metadata' => [
                        'website' => $website->toArray(),
                        'database_requests' => $databaseRequests,
                        'cron_jobs' => $cronJobs,
                    ],
                    'assigned_user_id' => $website->assigned_user_id,
                    'assigned_reseller_id' => $website->assigned_reseller_id,
                    'deleted_by' => $request->user()?->id,
                ]);

                if ($domain !== '') {
                    DatabaseRequest::query()->where('domain', $domain)->delete();
                }

                CronJob::query()->where('website_id', $id)->delete();
                $website->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Website destroy failed', [
                'website_id' => $id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            $this->restoreSystemCronJobs($cronJobModels, $website);

            return $this->websiteDestroyError($request, 'Website destroy failed.', 'destroy', $e);
        }

        $message = 'Website deleted successfully.';
        if ($cleanupMessages !== []) {
            $message .= ' '.implode(' ', $cleanupMessages);
        }

        if ($request->header('X-Inertia')) {
            return redirect()->route('websites.list')->with('success', $message);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'type' => 'success',
                'message' => $message,
            ]);
        }

        return redirect()->route('websites.list')->with('success', $message);
    }

    private function websiteDestroyError(Request $request, string $message, string $errorKey, \Throwable $error): RedirectResponse|JsonResponse
    {
        $detail = $message.' '.$error->getMessage();
        if ($request->header('X-Inertia')) {
            return back()->with('error', $detail);
        }

        return response()->json([
            'type' => 'error',
            'message' => $message,
            'errors' => [$errorKey => $error->getMessage()],
        ], 422);
    }

    private function restoreSystemCronJobs(iterable $cronJobs, Website $website): void
    {
        foreach ($cronJobs as $cronJob) {
            try {
                $this->cronSystemService->sync($cronJob, $website);
            } catch (\Throwable $e) {
                Log::critical('System cron rollback failed', [
                    'website_id' => (string) $website->id,
                    'cron_job_id' => (string) $cronJob->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function updateAlias(Request $request, string $token, string $id): JsonResponse
    {
        $alias = Website::query()
            ->visibleTo($request->user())
            ->firstWhere('id', $id);

        abort_if($alias === null, 404);
        abort_unless(in_array(strtolower((string) $alias->type), ['alis', 'alias'], true), 404);

        $validated = $request->validate([
            'domain' => [
                'required',
                'string',
                'max:253',
                'regex:/^(?=.{1,253}$)(?!-)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/',
            ],
        ]);
        $newDomain = strtolower(trim((string) $validated['domain']));
        $oldDomain = strtolower(trim((string) $alias->domain));

        $domainExists = Website::query()
            ->where('id', '!=', $id)
            ->whereRaw('LOWER(domain) = ?', [$newDomain])
            ->exists();
        if ($domainExists) {
            return response()->json([
                'type' => 'error',
                'message' => 'This domain already exists.',
                'errors' => ['domain' => ['This domain already exists.']],
            ], 422);
        }

        if ($newDomain === $oldDomain) {
            return response()->json([
                'type' => 'success',
                'message' => 'Alias domain is unchanged.',
                'website' => $alias,
            ]);
        }

        DB::transaction(function () use ($alias, $oldDomain, $newDomain): void {
            Domain::query()->where('name', $oldDomain)->update([
                'name' => $newDomain,
                'ssl_enabled' => false,
                'ssl_status' => 'disabled',
                'ssl_expires_at' => null,
                'ssl_checked_at' => now(),
            ]);
            SslCertificate::query()->where('domain', $oldDomain)->delete();

            $alias->forceFill([
                'domain' => $newDomain,
                'hostname' => $newDomain,
                'enable_ssl' => false,
                'ssl_mode' => 'none',
                'status' => 'live',
            ])->save();
        });

        return response()->json([
            'type' => 'success',
            'message' => 'Alias domain updated successfully. Issue SSL for the new domain if needed.',
            'website' => $alias->fresh(),
        ]);
    }

    private function destroyLinkedDomain(Request $request, Website $website, string $domain): RedirectResponse|JsonResponse
    {
        try {
            DB::transaction(function () use ($website, $domain): void {
                SslCertificate::query()
                    ->where(function ($query) use ($website, $domain): void {
                        $query->where('website_id', (string) $website->id);
                        if ($domain !== '') {
                            $query->orWhere('domain', $domain);
                        }
                    })
                    ->delete();

                if ($domain !== '') {
                    Domain::query()->where('name', $domain)->delete();
                }

                $website->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Linked domain destroy failed', [
                'website_id' => (string) $website->id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            if ($request->header('X-Inertia')) {
                return back()->with('error', 'Domain remove failed. '.$e->getMessage());
            }

            return response()->json([
                'type' => 'error',
                'message' => 'Domain remove failed.',
                'errors' => ['destroy' => $e->getMessage()],
            ], 422);
        }

        $message = 'Domain removed without deleting shared files or system user.';
        if ($request->header('X-Inertia')) {
            return back()->with('success', $message);
        }

        return $request->expectsJson()
            ? response()->json(['type' => 'success', 'message' => $message])
            : back()->with('success', $message);
    }

    /**
     * @param array<int, array<string, mixed>> $databaseRequests
     * @param array<int, array<string, mixed>> $cronJobs
     * @return array{zip_path: string, zip_name: string}
     */
    private function archiveWebsiteViaDrust(Website $website, array $databaseRequests, array $cronJobs): array
    {
        $drustUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($drustUrl === '') {
            throw new \RuntimeException('drust archive API is not configured.');
        }

        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token === '') {
            throw new \RuntimeException('drust archive API token is missing.');
        }

        $trashDir = storage_path('app/website-trash');
        if (! is_dir($trashDir) && ! @mkdir($trashDir, 0775, true) && ! is_dir($trashDir)) {
            throw new \RuntimeException('Unable to prepare website trash directory.');
        }

        $zipName = sprintf(
            'deleted-%s-%s.zip',
            $this->sanitizeFilename((string) ($website->domain ?? 'site')),
            $this->sanitizeFilename((string) ($website->id ?? 'unknown'))
        );
        $zipPath = rtrim($trashDir, '/').'/'.$zipName;

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
            ->withToken($token)
            ->post(rtrim($drustUrl, '/').'/api/v1/website/archive', [
                'zip_path' => $zipPath,
                'website' => [
                    'id' => (string) $website->id,
                    'domain' => (string) ($website->domain ?? ''),
                    'root_path' => (string) ($website->root_path ?? ''),
                    'project_root' => (string) ($website->project_root ?? ''),
                    'start_directory' => (string) ($website->start_directory ?? ''),
                    'site_owner' => (string) ($website->site_owner ?? ''),
                    'php_version' => (string) ($website->php_version ?? ''),
                    'status' => (string) ($website->status ?? ''),
                    'type_field' => (string) ($website->type ?? ''),
                    'enable_ssl' => (bool) ($website->enable_ssl ?? false),
                    'database_requests' => $databaseRequests,
                    'cron_jobs' => $cronJobs,
                ],
            ]);

        if (! $response->ok()) {
            $error = $response->json('message');
            throw new \RuntimeException(
                is_string($error) && trim($error) !== ''
                    ? trim($error)
                    : (trim((string) $response->body()) ?: 'Website archive failed.')
            );
        }

        $json = $response->json();
        if (! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? 'Website archive failed.'));
        }

        return [
            'zip_path' => $zipPath,
            'zip_name' => $zipName,
        ];
    }

    private function deleteWebsiteViaDrust(Website $website, bool $deleteUser): void
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Rust website cleanup API is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('serverpanel.execution_api_timeout', 60))
            ->withToken($token)
            ->post(rtrim($baseUrl, '/').'/api/v1/website/delete', [
                'site_owner' => (string) $website->site_owner,
                'paths' => array_values(array_unique(array_filter([
                    (string) $website->project_root,
                    (string) $website->root_path,
                ]))),
                'delete_user' => $deleteUser,
            ]);

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Rust website cleanup failed.'));
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($filename)) ?? '';
        $filename = trim($filename, '.-_');

        return $filename !== '' ? $filename : 'site';
    }
}
