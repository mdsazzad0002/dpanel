<?php

namespace App\Services\Website;

use App\Models\Website;
use App\Http\Controllers\PhpManagementController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebsiteCreateEditService
{
    public function __construct(
        protected WebsiteResolverService $resolver,
        protected WebsiteLifecycleService $lifecycle,
    ) {
    }

    /**
     * @param array<string, callable> $deps
     */
    public function store(Request $request, array $deps): RedirectResponse|JsonResponse
    {
        $validated = $this->validateStorePayload($request, $deps);
        $bootstrap = $this->lifecycle->bootstrapCreate($request, $validated, $deps);
        if ($bootstrap instanceof RedirectResponse || $bootstrap instanceof JsonResponse) {
            return $bootstrap;
        }

        $requests = (array) $deps['readRequests']();
        $defaultResellerId = $deps['defaultResellerId']();
        $runtimeStatus = $deps['detectRuntimeStatus']([
            'domain' => $validated['domain'],
            'root_path' => $validated['root_path'],
            'status' => 'pending',
        ]);

        $requests[] = [
            'id' => (string) str()->uuid(),
            'domain' => $validated['domain'],
            'start_directory' => $validated['start_directory'],
            'root_path' => $validated['root_path'],
            'project_root' => $validated['project_root'],
            'site_owner' => $validated['site_owner'],
            'php_version' => $validated['php_version'],
            'app_installer' => $validated['app_installer'],
            'wordpress_version' => $validated['wordpress_version'],
            'enable_ssl' => $validated['enable_ssl'],
            'assigned_user_id' => null,
            'assigned_reseller_id' => $defaultResellerId,
            'command' => (string) ($bootstrap['command'] ?? ''),
            'status' => $runtimeStatus,
            'created_at' => now()->toIso8601String(),
        ];
        $deps['writeRequests']($requests);

        $installerLabel = $validated['app_installer'] === 'wordpress' ? 'WordPress' : 'Starter';
        $message = "Website request created successfully. Installer: {$installerLabel}.";
        if (! empty($validated['enable_ssl'])) {
            $sslNotice = $bootstrap['ssl_notice'] ?? null;
            $message .= $sslNotice !== null ? ' '.$sslNotice : ' SSL was auto-generated.';
        }

        return redirect()->route('websites.list')->with('success', $message);
    }

    /**
     * @param array<string, callable> $deps
     */
    public function update(Request $request, string $id, array $deps): RedirectResponse|JsonResponse
    {
        $existingRequest = $this->resolver->findAuthorizedWebsiteOrFail($id, $request->user());
        $validated = $this->validateUpdatePayload($request, $id, $deps, $existingRequest);
        $bootstrap = $this->lifecycle->bootstrapUpdate($request, $validated, $existingRequest, $deps);
        if ($bootstrap instanceof RedirectResponse || $bootstrap instanceof JsonResponse) {
            return $bootstrap;
        }

        $requests = collect((array) $deps['readRequests']())->map(function (array $item) use ($id, $validated, $bootstrap, $deps): array {
            if ((string) ($item['id'] ?? '') !== $id) {
                return $item;
            }

            $item['domain'] = $validated['domain'];
            $item['start_directory'] = $validated['start_directory'];
            $item['root_path'] = $validated['root_path'];
            $item['project_root'] = $validated['project_root'];
            $item['site_owner'] = $validated['site_owner'];
            $item['php_version'] = $validated['php_version'];
            $item['app_installer'] = $validated['app_installer'];
            $item['wordpress_version'] = $validated['wordpress_version'];
            $item['wordpress_db_prefix'] = (string) ($item['wordpress_db_prefix'] ?? '');
            $item['enable_ssl'] = $validated['enable_ssl'];
            $item['command'] = (string) ($bootstrap['command'] ?? '');
            $item['status'] = $deps['detectRuntimeStatus']([
                'domain' => $validated['domain'],
                'root_path' => $validated['root_path'],
                'status' => (string) ($item['status'] ?? 'pending'),
            ]);
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $deps['writeRequests']($requests);

        $message = 'Website request updated successfully.';
        if (! empty($validated['enable_ssl'])) {
            $sslNotice = $bootstrap['ssl_notice'] ?? null;
            $message .= $sslNotice !== null ? ' '.$sslNotice : ' SSL was auto-generated.';
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'website' => $deps['websiteModelToArray'](Website::query()->findOrFail($id)),
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * @param array<string, callable> $deps
     * @return array<string, mixed>
     */
    protected function validateStorePayload(Request $request, array $deps): array
    {
        $validated = $request->validate([
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?=.{1,253}$)(?!-)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/',
            ],
            'root_path' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    if (! $this->resolver->pathStartsWith($this->resolver->normalizeAbsolutePath($value), $this->resolver->websiteBaseDirectory().'/')) {
                        $fail("The {$attribute} must be inside ".$this->resolver->websiteBaseDirectory().' and follow <base>/<owner>/<site_dir>.');
                    }
                },
            ],
            'start_directory' => ['nullable', 'string', 'max:255'],
            'php_version' => ['required', 'string', 'max:10'],
            'app_installer' => ['nullable', 'string', 'in:none,starter,wordpress'],
            'wordpress_version' => ['nullable', 'string', 'max:20', 'regex:/^(latest|\\d+\\.\\d+(?:\\.\\d+)?)$/i'],
            'enable_ssl' => ['boolean'],
        ]);

        $validated['app_installer'] = strtolower(trim((string) ($validated['app_installer'] ?? 'none')));
        if ($validated['app_installer'] === 'starter') {
            $validated['app_installer'] = 'none';
        }
        $validated['wordpress_version'] = $this->resolver->normalizeWordPressVersion((string) ($validated['wordpress_version'] ?? 'latest'));
        $validated['php_version'] = PhpManagementController::normalizePhpVersionSelection((string) ($validated['php_version'] ?? ''), []);
        $validated['start_directory'] = $this->resolver->normalizeSiteDirectory((string) ($validated['start_directory'] ?? 'public'), 'public');
        $validated['domain'] = $this->resolver->normalizeDomain((string) $validated['domain']);

        $domainExists = collect((array) ($deps['readRequests'] ?? fn (): array => [])())
            ->contains(fn (array $item): bool => $this->resolver->normalizeDomain((string) ($item['domain'] ?? '')) === $validated['domain']);
        if ($domainExists) {
            abort(response()->json(['errors' => ['domain' => ['This domain already exists.']]], 422));
        }

        $validated['root_path'] = $this->resolver->normalizeRootPath((string) ($validated['root_path'] ?? ''), $validated['domain']);
        $validated['project_root'] = $this->resolver->deriveProjectRootPath($validated['root_path'], $validated['domain']);
        $validated['site_owner'] = $this->resolver->extractSiteOwnerFromRootPath($validated['project_root']);
        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);

        return $validated;
    }

    /**
     * @param array<string, callable> $deps
     * @param array<string, mixed> $existingRequest
     * @return array<string, mixed>
     */
    protected function validateUpdatePayload(Request $request, string $id, array $deps, array $existingRequest): array
    {
        $validated = $request->validate([
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?=.{1,253}$)(?!-)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/',
            ],
            'root_path' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }

                    if (! $this->resolver->pathStartsWith($this->resolver->normalizeAbsolutePath($value), $this->resolver->websiteBaseDirectory().'/')) {
                        $fail("The {$attribute} must be inside ".$this->resolver->websiteBaseDirectory().' and follow <base>/<owner>/<site_dir>.');
                    }
                },
            ],
            'start_directory' => ['nullable', 'string', 'max:255'],
            'php_version' => ['required', 'string', 'max:10'],
            'app_installer' => ['nullable', 'string', 'in:none,starter,wordpress'],
            'wordpress_version' => ['nullable', 'string', 'max:20', 'regex:/^(latest|\\d+\\.\\d+(?:\\.\\d+)?)$/i'],
            'enable_ssl' => ['boolean'],
        ]);

        $validated['app_installer'] = strtolower(trim((string) ($validated['app_installer'] ?? ($existingRequest['app_installer'] ?? 'none'))));
        if ($validated['app_installer'] === 'starter') {
            $validated['app_installer'] = 'none';
        }
        $validated['wordpress_version'] = $this->resolver->normalizeWordPressVersion((string) ($validated['wordpress_version'] ?? ($existingRequest['wordpress_version'] ?? 'latest')));
        $validated['php_version'] = PhpManagementController::normalizePhpVersionSelection((string) ($validated['php_version'] ?? ''), []);
        $validated['start_directory'] = $this->resolver->normalizeSiteDirectory((string) ($validated['start_directory'] ?? ''), 'public');
        $validated['domain'] = $this->resolver->normalizeDomain((string) $validated['domain']);

        $domainExists = collect((array) ($deps['readRequests'] ?? fn (): array => [])())
            ->contains(function (array $item) use ($id, $validated): bool {
                if ((string) ($item['id'] ?? '') === $id) {
                    return false;
                }

                return $this->resolver->normalizeDomain((string) ($item['domain'] ?? '')) === $validated['domain'];
            });
        if ($domainExists) {
            abort(response()->json(['errors' => ['domain' => ['This domain already exists.']]], 422));
        }

        $validated['root_path'] = $this->resolver->normalizeRootPath((string) ($validated['root_path'] ?? ''), $validated['domain']);
        $validated['project_root'] = $this->resolver->deriveProjectRootPath($validated['root_path'], $validated['domain']);
        $validated['site_owner'] = $this->resolver->extractSiteOwnerFromRootPath($validated['project_root']);
        $validated['enable_ssl'] = (bool) ($validated['enable_ssl'] ?? false);

        return $validated;
    }
}
