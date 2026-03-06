<?php

namespace App\Http\Controllers;

use App\Models\DatabaseRequest as DatabaseRequestModel;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * Store database request. Command execution is intentionally disabled.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->migrateLegacyJsonRequests();

        $validated = $this->validatePayload($request);
        $command = $this->buildCommand($validated);

        // Intentionally disabled: command execution must be manually enabled later.
        // $output = [];
        // $exitCode = 0;
        // exec($command . ' 2>&1', $output, $exitCode);

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
            'status' => 'pending',
        ]);

        return redirect()->route('databases.list')->with('success', 'Database request created. Command execution is currently disabled in controller.');
    }

    /**
     * Show edit form.
     */
    public function edit(string $id): Response
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
    public function update(Request $request, string $id): RedirectResponse
    {
        $this->migrateLegacyJsonRequests();

        $validated = $this->validatePayload($request);

        $requestItem = DatabaseRequestModel::query()->find($id);
        if (! $requestItem) {
            return redirect()->route('databases.list')->with('error', 'Database request not found.');
        }

        $requestItem->fill([
            'database_name' => $validated['database_name'],
            'database_user' => $validated['database_user'],
            'database_password' => $validated['database_password'],
            'database_host' => $validated['database_host'],
            'domain' => $validated['domain'],
            'charset' => $validated['charset'],
            'collation' => $validated['collation'],
            'command' => $this->buildCommand($validated),
        ]);
        $requestItem->save();

        return redirect()->route('databases.list')->with('success', 'Database request updated successfully.');
    }

    /**
     * Delete a database request.
     */
    public function destroy(string $id): RedirectResponse
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
    public function openPhpMyAdmin(Request $request, string $id)
    {
        $this->migrateLegacyJsonRequests();

        $requestItem = DatabaseRequestModel::query()->find($id);
        abort_if($requestItem === null, 404);

        $origin = $request->getSchemeAndHttpHost();
        $targetUrl = rtrim((string) config('app.phpmyadmin_url', env('PHPMYADMIN_URL', "{$origin}/phpmyadmin/index.php")), '/');
        $helperUrl = (string) env('PHPMYADMIN_HELPER_URL', "{$origin}/phpmyadmin/phpmyadminsignin.php");

        return response()->view('phpmyadmin.autologin', [
            'targetUrl' => $targetUrl,
            'helperUrl' => $helperUrl,
            'username' => (string) ($requestItem->database_user ?? ''),
            'password' => (string) ($requestItem->database_password ?? ''),
            'database' => (string) ($requestItem->database_name ?? ''),
            'host' => (string) ($requestItem->database_host ?? 'localhost'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'database_name' => ['required', 'string', 'max:64'],
            'database_user' => ['required', 'string', 'max:64'],
            'database_password' => ['required', 'string', 'max:255'],
            'database_host' => ['required', 'string', 'max:255'],
            'charset' => ['required', 'string', 'max:32'],
            'collation' => ['required', 'string', 'max:64'],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildCommand(array $payload): string
    {
        return sprintf(
            '/usr/local/bin/serverinstaller-db create --domain=%s --name=%s --user=%s --password=%s --host=%s --charset=%s --collation=%s',
            escapeshellarg((string) $payload['domain']),
            escapeshellarg((string) $payload['database_name']),
            escapeshellarg((string) $payload['database_user']),
            escapeshellarg((string) $payload['database_password']),
            escapeshellarg((string) $payload['database_host']),
            escapeshellarg((string) $payload['charset']),
            escapeshellarg((string) $payload['collation']),
        );
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
