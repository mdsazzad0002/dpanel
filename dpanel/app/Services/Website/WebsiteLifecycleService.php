<?php

namespace App\Services\Website;

use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebsiteLifecycleService
{
    /**
     * @param array<string, callable> $callbacks
     * @return array<string, mixed>|RedirectResponse|JsonResponse
     */
    public function bootstrapCreate(Request $request, array $validated, array $callbacks): array|RedirectResponse|JsonResponse
    {
        return $this->bootstrapWebsite($request, $validated, $callbacks, null);
    }

    /**
     * @param array<string, mixed> $existingRequest
     * @param array<string, callable> $callbacks
     * @return array<string, mixed>|RedirectResponse|JsonResponse
     */
    public function bootstrapUpdate(Request $request, array $validated, array $existingRequest, array $callbacks): array|RedirectResponse|JsonResponse
    {
        return $this->bootstrapWebsite($request, $validated, $callbacks, $existingRequest);
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, callable> $callbacks
     */
    public function storeWebsite(Request $request, array $validated, array $callbacks): RedirectResponse|JsonResponse
    {
        $bootstrap = $this->bootstrapCreate($request, $validated, $callbacks);
        if ($bootstrap instanceof RedirectResponse || $bootstrap instanceof JsonResponse) {
            return $bootstrap;
        }

        $requests = (array) $callbacks['readRequests']();
        $defaultResellerId = $callbacks['defaultResellerId']();
        $runtimeStatus = $callbacks['detectRuntimeStatus']([
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
            'enable_ssl' => $validated['enable_ssl'],
            'assigned_user_id' => null,
            'assigned_reseller_id' => $defaultResellerId,
            'command' => (string) ($bootstrap['command'] ?? ''),
            'status' => $runtimeStatus,
            'created_at' => now()->toIso8601String(),
        ];
        $callbacks['writeRequests']($requests);

        $message = 'Website request created successfully.';
        if (! empty($validated['enable_ssl'])) {
            $sslNotice = $bootstrap['ssl_notice'] ?? null;
            $message .= $sslNotice !== null ? ' '.$sslNotice : ' SSL was auto-generated.';
        }

        return redirect()->route('websites.list')->with('success', $message);
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $existingRequest
     * @param array<string, callable> $callbacks
     */
    public function updateWebsite(Request $request, string $id, array $validated, array $existingRequest, array $callbacks): RedirectResponse|JsonResponse
    {
        $bootstrap = $this->bootstrapUpdate($request, $validated, $existingRequest, $callbacks);
        if ($bootstrap instanceof RedirectResponse || $bootstrap instanceof JsonResponse) {
            return $bootstrap;
        }

        $requests = collect((array) $callbacks['readRequests']())->map(function (array $item) use ($id, $validated, $bootstrap, $callbacks): array {
            if ((string) ($item['id'] ?? '') !== $id) {
                return $item;
            }

            $item['domain'] = $validated['domain'];
            $item['start_directory'] = $validated['start_directory'];
            $item['root_path'] = $validated['root_path'];
            $item['project_root'] = $validated['project_root'];
            $item['site_owner'] = $validated['site_owner'];
            $item['php_version'] = $validated['php_version'];
            $item['wordpress_db_prefix'] = (string) ($item['wordpress_db_prefix'] ?? '');
            $item['enable_ssl'] = $validated['enable_ssl'];
            $item['command'] = (string) ($bootstrap['command'] ?? '');
            $item['status'] = $callbacks['detectRuntimeStatus']([
                'domain' => $validated['domain'],
                'root_path' => $validated['root_path'],
                'status' => (string) ($item['status'] ?? 'pending'),
            ]);
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $callbacks['writeRequests']($requests);

        $message = 'Website request updated successfully.';
        if (! empty($validated['enable_ssl'])) {
            $sslNotice = $bootstrap['ssl_notice'] ?? null;
            $message .= $sslNotice !== null ? ' '.$sslNotice : ' SSL was auto-generated.';
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'website' => $callbacks['websiteModelToArray'](Website::query()->findOrFail($id)),
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, callable> $callbacks
     * @param array<string, mixed>|null $existingRequest
     * @return array<string, mixed>|RedirectResponse|JsonResponse
     */
    protected function bootstrapWebsite(Request $request, array $validated, array $callbacks, ?array $existingRequest): array|RedirectResponse|JsonResponse
    {
        $command = $callbacks['buildCommand']($validated);
        $installerBinary = '/usr/local/bin/serverinstaller-site';

        if (! is_file($installerBinary) || ! is_executable($installerBinary)) {
            return back()->withErrors([
                'error' => 'Website installer command is missing on this server.',
            ]);
        }

        try {
            $output = [];
            $exitCode = 0;
            exec($command.' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                $message = trim(implode("\n", $output));

                return back()->withErrors([
                    'error' => $message !== ''
                        ? 'Website installer command failed: '.$message
                        : 'Website installer command failed.',
                ]);
            }

            $callbacks['applyWebsiteFilesystemIsolation'](
                $validated['site_owner'],
                $validated['project_root'],
                $validated['root_path'],
            );

            if ($response = $callbacks['ensureWebsiteFoldersExist']($request, $validated['root_path'], $validated['project_root'], $existingRequest === null ? 'create' : 'update')) {
                return $response;
            }

            $callbacks['initializeWebsiteStarterFiles'](
                $validated['root_path'],
                $validated['domain'],
                (string) $validated['php_version'],
            );
            $callbacks['relocateApacheDefaultPage']();
            $callbacks['syncLiveWebVhost'](
                $validated['domain'],
                $validated['root_path'],
                (string) $validated['php_version'],
                $existingRequest['domain'] ?? null,
            );

            $sslNotice = null;
            if (! empty($validated['enable_ssl'])) {
                $sslResult = $callbacks['runIssueSslScript'](
                    $validated['domain'],
                    $validated['root_path'],
                    $callbacks['shouldAddWwwAlias']($validated['domain']),
                );

                if (! $sslResult['ran']) {
                    $sslNotice = 'SSL auto-generate is not available on this server.';
                } elseif (! $sslResult['success']) {
                    $sslNotice = trim($sslResult['output']) !== ''
                        ? 'SSL auto-generate failed: '.trim($sslResult['output'])
                        : 'SSL auto-generate failed.';
                } else {
                    $callbacks['syncLiveWebVhost'](
                        $validated['domain'],
                        $validated['root_path'],
                        (string) $validated['php_version'],
                        $existingRequest['domain'] ?? null,
                    );
                }
            }

            return [
                'command' => $command,
                'ssl_notice' => $sslNotice,
            ];
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
