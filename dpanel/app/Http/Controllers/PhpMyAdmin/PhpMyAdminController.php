<?php

namespace App\Http\Controllers\PhpMyAdmin;

use App\Http\Controllers\Controller;
use App\Services\PhpMyAdmin\DatabaseAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class PhpMyAdminController extends Controller
{
    public function index(Request $request, string $token, DatabaseAdminService $service): Response
    {
        return Inertia::render('PhpMyAdmin/Index', [
            'panelToken' => $token,
            'server' => $service->serverInfo(),
            'initialSelection' => [
                'database' => $this->resolveIdentifier($request->query('database', '')),
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
        $database = $this->resolveIdentifier($request->query('database', ''));
        $table = $this->resolveIdentifier($request->query('table', ''));
        $activeDatabase = $database !== '' ? $database : $service->currentDatabase();

        return Inertia::render('PhpMyAdmin/Sql', [
            'panelToken' => $token,
            'server' => $service->serverInfo(),
            'initialSelection' => [
                'database' => $activeDatabase,
                'table' => $table,
            ],
            'queryDefaults' => [
                'sql' => $this->defaultSql($activeDatabase, $table),
            ],
        ]);
    }

    public function databases(Request $request, string $token, DatabaseAdminService $service): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'databases' => $service->listDatabases(),
            'current_database' => $service->currentDatabase(),
        ]);
    }

    public function database(Request $request, string $token, string $database, DatabaseAdminService $service): JsonResponse
    {
        $database = $this->resolveIdentifier($database);
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
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(200, max(10, (int) $request->integer('perPage', 25)));

        return response()->json([
            'ok' => true,
            'table_details' => $service->tableDetails($database, $table, $page, $perPage),
        ]);
    }

    public function health(Request $request, string $token, DatabaseAdminService $service): JsonResponse
    {
        try {
            $database = $this->resolveDatabase($request->query('database', ''), $service);

            return response()->json([
                'ok' => true,
                'message' => 'First-party database module is ready.',
                'checks' => [
                    'session' => [
                        'ok' => true,
                        'message' => 'Panel session is active.',
                    ],
                    'connection' => [
                        'ok' => true,
                        'message' => 'Database connection established.',
                        'driver' => $service->serverInfo()['driver'],
                        'database' => $database,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'First-party database module failed health checks.',
                'checks' => [
                    'session' => [
                        'ok' => true,
                        'message' => 'Panel session is active.',
                    ],
                    'connection' => [
                        'ok' => false,
                        'message' => $e->getMessage(),
                    ],
                ],
            ], 422);
        }
    }

    public function execute(Request $request, string $token, DatabaseAdminService $service): JsonResponse
    {
        $validated = $request->validate([
            'sql' => ['required', 'string', 'max:20000'],
            'database' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
        ]);

        try {
            $result = $service->executeSql((string) $validated['sql'], $validated['database'] ?? null);

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

    private function resolveDatabase(mixed $value, DatabaseAdminService $service): string
    {
        $database = $this->resolveIdentifier($value);

        if ($database !== '') {
            return $database;
        }

        $fallback = $service->currentDatabase();

        return $this->resolveIdentifier($fallback);
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
