<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRequest as DatabaseRequestModel;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    private const LEGACY_MIGRATION_FLAG_FILE = 'database-requests.migrated';

    /**
     * Show database create form.
     */
    public function create(): Response
    {
        $this->migrateLegacyJsonRequests();

        return Inertia::render('Databases/Create', [
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    /**
     * List created database requests.
     */
    public function index(): Response
    {
        $this->migrateLegacyJsonRequests();

        $requests = DatabaseRequestModel::query()
            ->with('assignedUser:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DatabaseRequestModel $request): array => $this->databaseRequestToArray($request))
            ->values()
            ->all();

        return Inertia::render('Databases/List', [
            'databaseRequests' => $requests,
        ]);
    }

    /**
     * Store database request and sync database/user on server.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->migrateLegacyJsonRequests();

        $prepared = $this->preparePayload($request->all(), $request->user());
        $validated = $this->normalizePayload($this->validatePayload($prepared));
        $command = $this->buildCommand($validated);
        $syncResult = $this->syncDatabaseToServer($validated);
        $status = $syncResult['success'] ? 'active' : ($syncResult['ran'] ? 'failed' : 'pending');

        DatabaseRequestModel::query()->create([
            'id' => (string) str()->uuid(),
            'domain' => $validated['domain'],
            'database_name' => $validated['database_name'],
            'database_user' => $validated['database_user'],
            'database_password' => $validated['database_password'],
            'database_host' => $validated['database_host'],
            'charset' => $validated['charset'],
            'collation' => $validated['collation'],
            'command' => $command,
            'status' => $status,
            'assigned_user_id' => $request->user()?->id,
        ]);

        if ($syncResult['success']) {
            return redirect()->route('databases.list')->with('success', 'Database created and synced to MySQL/MariaDB successfully.');
        }

        if ($syncResult['ran']) {
            return redirect()->route('databases.list')->with('error', 'Database request saved, but server sync failed: '.$syncResult['output']);
        }

        return redirect()->route('databases.list')->with('error', 'Database request saved as pending. Server sync did not run on this environment.');
    }

    /**
     * Show edit form.
     */
    public function edit(string $token, string $id): Response
    {
        $this->migrateLegacyJsonRequests();

        $requestItem = DatabaseRequestModel::query()->find($id);
        abort_if($requestItem === null, 404);

        return Inertia::render('Databases/Edit', [
            'databaseRequest' => $this->databaseRequestToArray($requestItem),
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    /**
     * Update a database request.
     */
    public function update(Request $request, string $token, string $id): RedirectResponse
    {
        $this->migrateLegacyJsonRequests();

        $prepared = $this->preparePayload($request->all(), $request->user());
        $validated = $this->normalizePayload($this->validatePayload($prepared));

        $requestItem = DatabaseRequestModel::query()->find($id);
        if (! $requestItem) {
            return redirect()->route('databases.list')->with('error', 'Database request not found.');
        }

        $syncResult = $this->syncDatabaseToServer($validated);
        $status = $syncResult['success'] ? 'active' : ($syncResult['ran'] ? 'failed' : 'pending');

        $requestItem->fill([
            'database_name' => $validated['database_name'],
            'database_user' => $validated['database_user'],
            'database_password' => $validated['database_password'],
            'database_host' => $validated['database_host'],
            'domain' => $validated['domain'],
            'charset' => $validated['charset'],
            'collation' => $validated['collation'],
            'command' => $this->buildCommand($validated),
            'status' => $status,
            'assigned_user_id' => $request->user()?->id,
        ]);
        $requestItem->save();

        if ($syncResult['success']) {
            return redirect()->route('databases.list')->with('success', 'Database request updated and synced successfully.');
        }

        if ($syncResult['ran']) {
            return redirect()->route('databases.list')->with('error', 'Request updated, but server sync failed: '.$syncResult['output']);
        }

        return redirect()->route('databases.list')->with('error', 'Request updated as pending. Server sync did not run on this environment.');
    }

    /**
     * Delete a database request.
     */
    public function destroy(string $token, string $id): RedirectResponse
    {
        $this->migrateLegacyJsonRequests();

        $deleted = DatabaseRequestModel::query()->where('id', $id)->delete();
        if ($deleted === 0) {
            return redirect()->route('databases.list')->with('error', 'Database request not found.');
        }

        return redirect()->route('databases.list')->with('success', 'Database request deleted successfully.');
    }

    /**
     * Open phpMyAdmin with selected database credentials and preselected DB.
     */
    public function openPhpMyAdmin(Request $request, string $token, string $id)
    {
        $this->migrateLegacyJsonRequests();

        $requestItem = DatabaseRequestModel::query()->find($id);
        abort_if($requestItem === null, 404);

        $configuredTargetUrl = trim((string) config('app.phpmyadmin_url', ''));
        $configuredHelperUrl = trim((string) config('app.phpmyadmin_helper_url', ''));
        $isSeparateMode = $this->isWebtoolsSeparateMode();

        if ($configuredTargetUrl !== '') {
            $targetUrl = rtrim($configuredTargetUrl, '/');
        } else {
            $origin = rtrim($request->getSchemeAndHttpHost(), '/');
            if ($isSeparateMode) {
                $port = $this->normalizePort((int) config('app.phpmyadmin_port', 8090), 8090);
                $origin = $request->getScheme().'://'.$request->getHost().':'.$port;
                $targetUrl = $origin.'/index.php';
            } else {
                $targetUrl = $origin.'/phpmyadmin/index.php';
            }
        }

        if ($configuredHelperUrl !== '') {
            $helperUrl = $configuredHelperUrl;
        } else {
            $parsedTarget = parse_url($targetUrl);
            if (is_array($parsedTarget) && isset($parsedTarget['scheme'], $parsedTarget['host'])) {
                $helperBase = $parsedTarget['scheme'].'://'.$parsedTarget['host'];
                if (isset($parsedTarget['port'])) {
                    $helperBase .= ':'.$parsedTarget['port'];
                }
            } else {
                $helperBase = preg_replace('#/phpmyadmin/index\.php/?$#i', '', $targetUrl);
                $helperBase = is_string($helperBase) ? rtrim($helperBase, '/') : rtrim($targetUrl, '/');
            }
            $helperPath = $isSeparateMode ? '/phpmyadminsignin.php' : '/phpmyadmin/phpmyadminsignin.php';
            $helperUrl = $helperBase.$helperPath;
        }

        $databaseHost = trim((string) ($requestItem->database_host ?? '127.0.0.1'));
        if ($databaseHost === '' || strcasecmp($databaseHost, 'localhost') === 0) {
            $databaseHost = '127.0.0.1';
        }

        $tokenModeEnabled = trim((string) config('app.phpmyadmin_signon_issue_secret', '')) !== '';
        if ($tokenModeEnabled) {
            $token = $this->tryIssuePhpMyAdminToken($helperUrl, [
                'username' => (string) ($requestItem->database_user ?? ''),
                'password' => (string) ($requestItem->database_password ?? ''),
                'host' => $databaseHost,
                'db' => (string) ($requestItem->database_name ?? ''),
                'ttl' => 900,
            ]);
            if (is_string($token) && $token !== '') {
                $sep = str_contains($helperUrl, '?') ? '&' : '?';

                return redirect()->away($helperUrl.$sep.'token='.rawurlencode($token));
            }

            return redirect()
                ->route('databases.list')
                ->with('error', 'Secure phpMyAdmin auto-login failed (token issuing). Check PHPMYADMIN_SIGNON_SECRET and PMA_SIGNON_ISSUE_SECRET.');
        }

        return response()->view('phpmyadmin.autologin', [
            'targetUrl' => $targetUrl,
            'helperUrl' => $helperUrl,
            'username' => (string) ($requestItem->database_user ?? ''),
            'password' => (string) ($requestItem->database_password ?? ''),
            'database' => (string) ($requestItem->database_name ?? ''),
            'host' => $databaseHost,
        ]);
    }

    private function isWebtoolsSeparateMode(): bool
    {
        return filter_var((string) config('app.webtools_separate_ports', false), FILTER_VALIDATE_BOOL);
    }

    private function normalizePort(int $value, int $fallback): int
    {
        return $value >= 1 && $value <= 65535 ? $value : $fallback;
    }

    private function tryIssuePhpMyAdminToken(string $helperUrl, array $payload): ?string
    {
        $secret = trim((string) config('app.phpmyadmin_signon_issue_secret', ''));
        if ($secret === '') {
            return null;
        }

        $issueUrl = $helperUrl.(str_contains($helperUrl, '?') ? '&' : '?').'action=issue';

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(3)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$secret,
                    'X-ServerPanel-Signon' => $secret,
                ])
                ->post($issueUrl, $payload);

            if (! $response->ok()) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json) || ! ($json['success'] ?? false)) {
                return null;
            }

            $token = (string) ($json['token'] ?? '');
            return $token !== '' ? $token : null;
        } catch (\Throwable $e) {
            Log::debug('phpMyAdmin token issue failed: '.$e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $host = trim((string) ($payload['database_host'] ?? '127.0.0.1'));
        if ($host === '' || strcasecmp($host, 'localhost') === 0) {
            $host = '127.0.0.1';
        }
        $payload['database_host'] = $host;
        $payload['domain'] = strtolower(trim((string) ($payload['domain'] ?? '')));

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload): array
    {
        return Validator::make($payload, [
            'domain' => ['required', 'string', 'max:255'],
            'database_name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'database_user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'database_password' => ['required', 'string', 'max:255'],
            'database_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._%-]+$/'],
            'charset' => ['required', 'string', 'max:32'],
            'collation' => ['required', 'string', 'max:64'],
        ])->validate();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function preparePayload(array $input, ?\App\Models\User $user = null): array
    {
        $domain = strtolower(trim((string) ($input['domain'] ?? '')));
        $host = trim((string) ($input['database_host'] ?? '127.0.0.1'));
        $charset = trim((string) ($input['charset'] ?? 'utf8mb4'));
        $collation = trim((string) ($input['collation'] ?? 'utf8mb4_unicode_ci'));

        $prefix = $this->databasePrefixFromDomain($domain, $user);

        $databaseName = trim((string) ($input['database_name'] ?? ''));
        if ($databaseName === '') {
            $databaseName = $this->makeDatabaseIdentifier($prefix, 'db');
        }

        $databaseUser = trim((string) ($input['database_user'] ?? ''));
        if ($databaseUser === '') {
            $databaseUser = $this->makeDatabaseIdentifier($prefix, 'user');
        }

        $databasePassword = trim((string) ($input['database_password'] ?? ''));
        if ($databasePassword === '') {
            $databasePassword = $this->generateDatabasePassword();
        }

        return [
            'domain' => $domain,
            'database_name' => $databaseName,
            'database_user' => $databaseUser,
            'database_password' => $databasePassword,
            'database_host' => $host !== '' ? $host : '127.0.0.1',
            'charset' => $charset !== '' ? $charset : 'utf8mb4',
            'collation' => $collation !== '' ? $collation : 'utf8mb4_unicode_ci',
        ];
    }

    private function databasePrefixFromDomain(string $domain, ?\App\Models\User $user = null): string
    {
        $segment = '';
        if ($domain !== '') {
            $segment = (string) Str::of(explode('.', $domain)[0] ?? '')
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->limit(20, '');
        }

        if ($segment === '' && $user?->name) {
            $segment = (string) Str::of($user->name)
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->limit(20, '');
        }

        return $segment !== '' ? $segment : 'dpanel';
    }

    private function makeDatabaseIdentifier(string $prefix, string $suffix): string
    {
        $identifier = trim($prefix.'_'.$suffix, '_');
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '_', $identifier) ?? $identifier;

        return substr($identifier, 0, 64);
    }

    private function generateDatabasePassword(): string
    {
        try {
            return bin2hex(random_bytes(16)).'!A1';
        } catch (\Throwable $e) {
            return Str::random(24).'!A1';
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildCommand(array $payload): string
    {
        $scriptPath = base_path('scripts/database-request.sh');

        return sprintf(
            'bash %s create %s %s %s %s %s %s',
            escapeshellarg($scriptPath),
            escapeshellarg((string) $payload['database_name']),
            escapeshellarg((string) $payload['database_user']),
            escapeshellarg((string) $payload['database_password']),
            escapeshellarg((string) $payload['database_host']),
            escapeshellarg((string) $payload['charset']),
            escapeshellarg((string) $payload['collation']),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ran: bool, success: bool, output: string}
     */
    private function syncDatabaseToServer(array $payload): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ran' => false, 'success' => false, 'output' => 'Database sync skipped on Windows environment.'];
        }

        $scriptPath = base_path('scripts/database-request.sh');
        if (! is_file($scriptPath)) {
            return ['ran' => false, 'success' => false, 'output' => 'Database sync script not found: '.$scriptPath];
        }

        $parts = [
            'bash',
            escapeshellarg($scriptPath),
            'create',
            escapeshellarg((string) $payload['database_name']),
            escapeshellarg((string) $payload['database_user']),
            escapeshellarg((string) $payload['database_password']),
            escapeshellarg((string) $payload['database_host']),
            escapeshellarg((string) $payload['charset']),
            escapeshellarg((string) $payload['collation']),
        ];

        $output = [];
        $exitCode = 1;
        @exec(implode(' ', $parts).' 2>&1', $output, $exitCode);
        $message = trim(implode("\n", $output));
        $success = $exitCode === 0;

        if (! $success) {
            Log::warning('Database sync script failed', [
                'script' => $scriptPath,
                'exit_code' => $exitCode,
                'output' => $message,
                'payload' => [
                    'database_name' => (string) $payload['database_name'],
                    'database_user' => (string) $payload['database_user'],
                    'database_host' => (string) $payload['database_host'],
                    'charset' => (string) $payload['charset'],
                    'collation' => (string) $payload['collation'],
                    'domain' => (string) $payload['domain'],
                ],
            ]);
        }

        return [
            'ran' => true,
            'success' => $success,
            'output' => $message !== '' ? $message : ($success ? 'Database sync completed.' : 'Database sync failed.'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function readWebsiteDomains(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('websites')) {
                return [];
            }

            return Website::query()
                ->pluck('domain')
                ->filter(fn ($domain) => is_string($domain) && trim((string) $domain) !== '')
                ->map(fn ($domain) => strtolower(trim((string) $domain)))
                ->unique()
                ->sort()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseRequestToArray(DatabaseRequestModel $request): array
    {
        return [
            'id' => (string) $request->id,
            'domain' => (string) $request->domain,
            'database_name' => (string) $request->database_name,
            'database_user' => (string) $request->database_user,
            'database_password' => (string) $request->database_password,
            'database_host' => (string) $request->database_host,
            'charset' => (string) $request->charset,
            'collation' => (string) $request->collation,
            'command' => (string) ($request->command ?? ''),
            'status' => (string) ($request->status ?? 'pending'),
            'assigned_user_id' => $request->assigned_user_id ? (int) $request->assigned_user_id : null,
            'assigned_user_name' => $request->assignedUser?->name,
            'assigned_user_email' => $request->assignedUser?->email,
            'created_at' => optional($request->created_at)->toIso8601String(),
            'updated_at' => optional($request->updated_at)->toIso8601String(),
        ];
    }

    private function migrateLegacyJsonRequests(): void
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('database_requests')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        if (Storage::exists(self::LEGACY_MIGRATION_FLAG_FILE)) {
            return;
        }

        $legacyFiles = ['database-requests.json', 'private/database-requests.json'];
        $imported = 0;

        foreach ($legacyFiles as $legacyFile) {
            if (! Storage::exists($legacyFile)) {
                continue;
            }

            $decoded = json_decode((string) Storage::get($legacyFile), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = trim((string) ($item['id'] ?? ''));
                $domain = trim((string) ($item['domain'] ?? ''));
                $databaseName = trim((string) ($item['database_name'] ?? ''));
                $databaseUser = trim((string) ($item['database_user'] ?? ''));

                if ($domain === '' || $databaseName === '' || $databaseUser === '') {
                    continue;
                }

                DatabaseRequestModel::query()->updateOrCreate(
                    ['id' => $id !== '' ? $id : (string) str()->uuid()],
                    [
                        'domain' => strtolower($domain),
                        'database_name' => $databaseName,
                        'database_user' => $databaseUser,
                        'database_password' => (string) ($item['database_password'] ?? ''),
                        'database_host' => (string) ($item['database_host'] ?? 'localhost'),
                        'charset' => (string) ($item['charset'] ?? 'utf8mb4'),
                        'collation' => (string) ($item['collation'] ?? 'utf8mb4_unicode_ci'),
                        'command' => (string) ($item['command'] ?? ''),
                        'status' => (string) ($item['status'] ?? 'pending'),
                        'created_at' => $this->normalizeLegacyDatetime((string) ($item['created_at'] ?? '')),
                        'updated_at' => $this->normalizeLegacyDatetime((string) ($item['updated_at'] ?? '')),
                    ],
                );
                $imported++;
            }
        }

        Storage::put(self::LEGACY_MIGRATION_FLAG_FILE, json_encode([
            'migrated_at' => now()->toIso8601String(),
            'rows_imported' => $imported,
        ], JSON_PRETTY_PRINT));
    }

    private function normalizeLegacyDatetime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return now()->toDateTimeString();
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return now()->toDateTimeString();
        }
    }
}
