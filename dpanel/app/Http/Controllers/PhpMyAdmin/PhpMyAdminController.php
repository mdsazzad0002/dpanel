<?php

namespace App\Http\Controllers\PhpMyAdmin;

use App\Http\Controllers\Controller;
use App\Services\PhpMyAdmin\DatabaseAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PhpMyAdminController extends Controller
{
    public function index(Request $request, string $token, DatabaseAdminService $service): Response
    {
        $user = $request->user();
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($user);
        $requestedDatabase = $this->resolveIdentifier($request->query('database', ''));
        $accessibleDatabase = $allAccessRequested
            ? $requestedDatabase
            : $service->resolveAccessibleDatabase($user, $requestedDatabase !== '' ? $requestedDatabase : null);
        $accessibleDatabases = $service->listDatabasesForUser($user, null, $allAccessRequested);

        return Inertia::render('PhpMyAdmin/Index', [
            'panelToken' => $token,
            'server' => $service->serverInfo(),
            'accessControl' => [
                'mode' => $allAccessRequested ? 'global' : 'scoped',
                'databases' => $accessibleDatabases,
            ],
            'initialSelection' => [
                'database' => $accessibleDatabase,
                'table' => $this->resolveIdentifier($request->query('table', '')),
                'page' => max(1, (int) $request->integer('page', 1)),
                'perPage' => min(200, max(10, (int) $request->integer('perPage', 25))),
            ],
            'queryDefaults' => [
                'sql' => '',
            ],
        ]);
    }

    public function sql(Request $request, string $token, DatabaseAdminService $service): Response
    {
        $user = $request->user();
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($user);
        $database = $this->resolveIdentifier($request->query('database', ''));
        $database = $allAccessRequested
            ? $database
            : $service->resolveAccessibleDatabase($user, $database !== '' ? $database : null);
        $table = $this->resolveIdentifier($request->query('table', ''));

        return redirect()->route('phpmyadmin.index', [
            'token' => $token,
            'database' => $database,
            'table' => $table,
            'access' => $allAccessRequested ? 'all' : null,
        ]);
    }

    public function databases(Request $request, string $token, DatabaseAdminService $service): JsonResponse
    {
        $user = $request->user();
        $selectedDatabase = $this->resolveIdentifier($request->query('database', ''));

        return response()->json([
            'ok' => true,
            'databases' => $service->listDatabasesForUser($user, $selectedDatabase !== '' ? $selectedDatabase : null, $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($user)),
            'current_database' => $service->currentDatabase(),
            'selected_database' => $selectedDatabase,
        ]);
    }

    public function database(Request $request, string $token, string $database, DatabaseAdminService $service): JsonResponse
    {
        $database = $this->resolveIdentifier($database);
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if (! $service->canAccessDatabase($request->user(), $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(200, max(10, (int) $request->integer('perPage', 25)));
        $table = $this->resolveIdentifier($request->query('table', ''));

        $summary = $service->databaseSummary($database);
        $tables = $service->listTables($database);

        if ($table === '' && $tables !== []) {
            $table = (string) ($tables[0]['name'] ?? '');
        }

        $tableDetails = $table !== '' ? $service->tableDetails($database, $table, $page, $perPage) : null;

        return response()->json([
            'ok' => true,
            'database' => $database,
            'summary' => $summary,
            'tables' => $tables,
            'selected_table' => $table,
            'table_details' => $tableDetails,
            'query_defaults' => [
                'sql' => $this->defaultSql($database, $table),
            ],
        ]);
    }

    public function table(Request $request, string $token, string $database, string $table, DatabaseAdminService $service): JsonResponse
    {
        $database = $this->resolveIdentifier($database);
        $table = $this->resolveIdentifier($table);
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if (! $service->canAccessDatabase($request->user(), $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(200, max(10, (int) $request->integer('perPage', 25)));

        return response()->json([
            'ok' => true,
            'table_details' => $service->tableDetails($database, $table, $page, $perPage),
        ]);
    }

    public function destroyTable(Request $request, string $token, string $database, string $table, DatabaseAdminService $service): JsonResponse
    {
        $database = $this->resolveIdentifier($database);
        $table = $this->resolveIdentifier($table);
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if (! $service->canAccessDatabase($request->user(), $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }

        $service->dropTable($database, $table);

        return response()->json([
            'ok' => true,
            'message' => sprintf('Table %s.%s dropped successfully.', $database, $table),
        ]);
    }

    public function emptyTable(Request $request, string $token, string $database, string $table, DatabaseAdminService $service): JsonResponse
    {
        $database = $this->resolveIdentifier($database);
        $table = $this->resolveIdentifier($table);
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if (! $service->canAccessDatabase($request->user(), $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }

        $service->truncateTable($database, $table);

        return response()->json([
            'ok' => true,
            'message' => sprintf('Table %s.%s emptied successfully.', $database, $table),
        ]);
    }

    public function renameTable(Request $request, string $token, string $database, string $table, DatabaseAdminService $service): JsonResponse
    {
        $database = $this->resolveIdentifier($database);
        $table = $this->resolveIdentifier($table);
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if (! $service->canAccessDatabase($request->user(), $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }

        $validated = $request->validate([
            'new_table' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        $newTable = $this->resolveIdentifier($validated['new_table']);

        if ($newTable === '') {
            return response()->json([
                'ok' => false,
                'message' => 'New table name is required.',
            ], 422);
        }

        try {
            $service->renameTable($database, $table, $newTable);

            return response()->json([
                'ok' => true,
                'message' => sprintf('Table %s.%s renamed to %s.%s.', $database, $table, $database, $newTable),
                'database' => $database,
                'table' => $newTable,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Table rename failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function createTable(Request $request, string $token, string $database, DatabaseAdminService $service): JsonResponse
    {
        $database = $this->resolveIdentifier($database);
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if (! $service->canAccessDatabase($request->user(), $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }

        $validated = $request->validate([
            'table_name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'columns' => ['nullable', 'array', 'min:1'],
            'columns.*.name' => ['required_with:columns', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'columns.*.type' => ['required_with:columns', 'string', 'max:32'],
            'columns.*.length' => ['nullable', 'string', 'max:64'],
            'columns.*.nullable' => ['nullable', 'boolean'],
            'columns.*.defaultValue' => ['nullable', 'string', 'max:255'],
            'columns.*.unsigned' => ['nullable', 'boolean'],
            'columns.*.autoIncrement' => ['nullable', 'boolean'],
            'columns.*.primaryKey' => ['nullable', 'boolean'],
            'columns.*.comment' => ['nullable', 'string', 'max:255'],
            'columns.*.after' => ['nullable', 'string', 'max:64'],
        ]);

        $tableName = $this->resolveIdentifier($validated['table_name']);

        try {
            $service->createTable($database, $tableName, (array) ($validated['columns'] ?? []));

            return response()->json([
                'ok' => true,
                'message' => sprintf('Table %s.%s created successfully.', $database, $tableName),
                'database' => $database,
                'table' => $tableName,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Table creation failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function alterTableStructure(Request $request, string $token, string $database, string $table, DatabaseAdminService $service): JsonResponse
    {
        $database = $this->resolveIdentifier($database);
        $table = $this->resolveIdentifier($table);
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if (! $service->canAccessDatabase($request->user(), $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.'],
                403);
        }

        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.originalName' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'columns.*.name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'columns.*.type' => ['required', 'string', 'max:32'],
            'columns.*.length' => ['nullable', 'string', 'max:64'],
            'columns.*.nullable' => ['nullable', 'boolean'],
            'columns.*.defaultValue' => ['nullable', 'string', 'max:255'],
            'columns.*.unsigned' => ['nullable', 'boolean'],
            'columns.*.autoIncrement' => ['nullable', 'boolean'],
            'columns.*.primaryKey' => ['nullable', 'boolean'],
            'columns.*.comment' => ['nullable', 'string', 'max:255'],
            'columns.*.after' => ['nullable', 'string', 'max:64'],
            'columns.*.remove' => ['nullable', 'boolean'],
        ]);

        try {
            $service->alterTableStructure($database, $table, (array) $validated['columns']);

            return response()->json([
                'ok' => true,
                'message' => sprintf('Table %s.%s structure updated successfully.', $database, $table),
                'database' => $database,
                'table' => $table,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Structure update failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function execute(Request $request, string $token, DatabaseAdminService $service): JsonResponse
    {
        $validated = $request->validate([
            'sql' => ['required', 'string', 'max:20000'],
            'database' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        $user = $request->user();
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($user);
        $database = $this->resolveIdentifier($validated['database'] ?? '');
        $database = $database !== ''
            ? $database
            : $service->resolveAccessibleDatabase($user, null, $allAccessRequested);

        if ($database === '' || ! $service->canAccessDatabase($user, $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }

        try {
            $result = $service->executeSql((string) $validated['sql'], $database);

            return response()->json([
                'ok' => true,
                'message' => $result['mode'] === 'result'
                    ? 'Query returned rows.'
                    : 'Statement executed successfully.',
                'result' => $result,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Query execution failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request, string $token, DatabaseAdminService $service): BinaryFileResponse|JsonResponse
    {
        $validated = $request->validate([
            'database' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'scope' => ['nullable', 'string', 'in:database,table'],
            'table' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        $database = $this->resolveIdentifier($validated['database'] ?? '');
        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if ($database !== '' && ! $service->canAccessDatabase($request->user(), $database, $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }

        try {
            $table = ($validated['scope'] ?? 'database') === 'table'
                ? ($validated['table'] ?? null)
                : null;
            $artifact = $service->exportDatabase($database !== '' ? $database : null, $table);

            return response()
                ->download($artifact['path'], $artifact['filename'], [
                    'Content-Type' => 'application/sql; charset=utf-8',
                ])
                ->deleteFileAfterSend(true);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Export failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function import(Request $request, string $token, DatabaseAdminService $service): JsonResponse
    {
        $validated = $request->validate([
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'file' => ['required', 'file', 'max:51200'],
        ]);

        $allAccessRequested = $request->string('access')->toString() === 'all' && $service->canAccessAllDatabases($request->user());
        if (! $service->canAccessDatabase($request->user(), (string) $validated['database'], $allAccessRequested)) {
            return response()->json([
                'ok' => false,
                'message' => 'Database access denied.',
            ], 403);
        }

        $uploadedFile = $request->file('file');
        if ($uploadedFile === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Import file not found.',
            ], 422);
        }

        try {
            $contents = file_get_contents($uploadedFile->getRealPath()) ?: '';
            $result = $service->importDatabase((string) $validated['database'], $contents);

            return response()->json([
                'ok' => true,
                'message' => sprintf('Import completed for %s.', $result['database']),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Import failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveIdentifier(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }

        return $value;
    }

    private function defaultSql(string $database, string $table): string
    {
        if ($database !== '' && $table !== '') {
            return 'SELECT * FROM `'.$database.'`.`'.$table.'` LIMIT 25;';
        }

        if ($database !== '') {
            return 'SHOW TABLES FROM `'.$database.'`;';
        }

        return 'SHOW DATABASES;';
    }
}
