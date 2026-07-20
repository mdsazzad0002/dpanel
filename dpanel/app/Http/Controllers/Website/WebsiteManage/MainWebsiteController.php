<?php

namespace App\Http\Controllers\Website\WebsiteManage;

use App\Http\Controllers\Controller;
use App\Models\CronJob;
use App\Models\DatabaseRequest;
use App\Models\Website;
use App\Services\Filemanager\FilemanagerService;
use App\Services\PathService;
use App\Services\Php\PhpService;
use App\Services\ScriptPathResolver;
use App\Services\Website\WebsiteService;
use App\Services\Website\WebsiteTrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    ) {
    }

    /**
     * Show website creation page.
     */
    public function create(): Response
    {
        return Inertia::render('Websites/Create', [
            'serverBaseDir' => PathService::websiteBaseDirectory(),
            'phpVersions' => PhpService::getPhpVersions()]
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
                function (string $attribute, mixed $value, \Closure $fail) use ($domainType, $domainRegex): void {
                    $normalized = strtolower(trim((string) $value));

                    if ($domainType !== 'main' && $normalized === '') {
                        $fail("The {$attribute} field is required for this domain type.");
                        return;
                    }

                    if ($normalized !== '' && preg_match($domainRegex, $normalized) !== 1) {
                        $fail("The {$attribute} must be a valid domain name.");
                    }
                },
            ],
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
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    if (! $this->paths->pathStartsWith($this->paths->normalizeAbsolutePathValue($value), $this->paths->websiteBaseDirectoryValue().'/')) {
                        $fail("The {$attribute} must be inside ".$this->paths->websiteBaseDirectoryValue().' and follow <base>/<owner>/<site_dir>.');
                    }
                },
            ],
            'start_directory' => ['nullable', 'string', 'max:255'],
            'php_version' => ['required', 'string', 'max:10'],
            'domain_type' => ['required', 'string', 'in:main,alis,sub'],
            'enable_ssl' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'type' => 'error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();



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

        $homeSetup = $this->filemanagerService->createAccountHome($siteOwner, null, '/bin/bash', $siteDirectory);
        $projectRoot = $homeSetup['project_root'];
        $rootPath = $homeSetup['root_path'] ?? $homeSetup['public_html'];

        if (! is_dir($projectRoot) || ! is_dir($rootPath)) {
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
        $folderCheck = $this->filemanagerService->ensureWebsiteFoldersExist(
            $request,
            $rootPath,
            $projectRoot,
            'create',
            true
        );
        if ($folderCheck instanceof JsonResponse) {
            return $folderCheck;
        }




        // Demo page setup
        try {
            $startDirectory = array_key_exists('start_directory', $validated)
                ? trim((string) $validated['start_directory'])
                : null;
            if ($startDirectory === '') {
                $startDirectory = null;
            }

            $demoFiles = $this->websiteService->createDemoSitePage(
                $rootPath,
                (string) $validated['domain'],
                (string) $validated['php_version'],
                $startDirectory,
                $siteOwner,
            );
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
            'root_path' => $rootPath,
            'project_root' => $projectRoot,
            'start_directory' => array_key_exists('start_directory', $validated) ? trim((string) $validated['start_directory']) : null,
            'site_owner' => $siteOwner,
            'php_version' => (string) $validated['php_version'],
            'enable_ssl' => (bool) ($validated['enable_ssl'] ?? false),
            'filemanager_show_hidden' => false,
            'assigned_user_id' => null,
            'assigned_reseller_id' => ($request->user()?->hasRole('reseller') ? (int) $request->user()->id : null),
            'status' => 'pending',
            'type' => (string) $validated['domain_type'],
        ]);
        $message = 'Website created successfully.';

        return response()->json([
            'type' => 'success',
            'message' => $message.' Demo site files created successfully.',
            'demo_files' => $demoFiles,
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
        $cronJobs = CronJob::query()
            ->where('website_id', $id)
            ->get(['id', 'website_id', 'domain', 'name', 'expression', 'command', 'status', 'description'])
            ->map(fn (CronJob $job): array => [
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
            $archive = $this->websiteTrashService->archiveWebsite($website, $databaseRequests, $cronJobs);
            $cleanupMessages[] = 'Website files archived to '.$archive['zip_name'].'.';
        } catch (\Throwable $e) {
            Log::error('Website trash archive failed', [
                'website_id' => $id,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'type' => 'error',
                'message' => 'Website archive failed.',
                'errors' => [
                    'archive' => $e->getMessage(),
                ],
            ], 422);
        }

        if ($domain !== '') {
            $scriptPath = ScriptPathResolver::resolveScriptPath('sync-vhost');
            if (is_string($scriptPath) && $scriptPath !== '') {
                $result = ScriptPathResolver::runSystemScriptAsRootWithOutput($scriptPath, ['remove', $domain]);
                if ($result['success']) {
                    $cleanupMessages[] = 'Live vhost removed.';
                } else {
                    $message = trim((string) $result['output']);
                    $cleanupMessages[] = 'Live vhost removal failed: '.($message !== '' ? $message : 'unknown error');

                    return response()->json([
                        'type' => 'error',
                        'message' => 'Website vhost cleanup failed.',
                        'errors' => [
                            'vhost' => $message !== '' ? $message : 'Unable to remove live vhost.',
                        ],
                    ], 422);
                }
            }
        }

        try {
            DB::transaction(function () use ($website, $domain, $id): void {
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

            return response()->json([
                'type' => 'error',
                'message' => 'Website destroy failed.',
                'errors' => [
                    'destroy' => $e->getMessage(),
                ],
            ], 422);
        }

        $message = 'Website deleted successfully.';
        if ($cleanupMessages !== []) {
            $message .= ' '.implode(' ', $cleanupMessages);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'type' => 'success',
                'message' => $message,
            ]);
        }

        return redirect()->route('websites.list')->with('success', $message);
    }
}
