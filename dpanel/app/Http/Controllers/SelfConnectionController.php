<?php

namespace App\Http\Controllers;

use App\Support\EnvFileWriter;
use App\Support\SelfConnectionOrchestrator;
use App\Support\SelfConnectionSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SelfConnectionController extends Controller
{
    public function __construct(private readonly SelfConnectionSettings $settings)
    {
    }

    public function manager(Request $request): Response
    {
        $dbConnection = (string) config('database.default');

        return Inertia::render('Settings/SelfConnection', [
            'current' => [
                'database' => [
                    'host' => (string) config("database.connections.{$dbConnection}.host"),
                    'port' => (int) config("database.connections.{$dbConnection}.port"),
                    'database' => (string) config("database.connections.{$dbConnection}.database"),
                    'username' => SelfConnectionSettings::maskUsername((string) config("database.connections.{$dbConnection}.username")),
                    'charset' => (string) config("database.connections.{$dbConnection}.charset"),
                    'collation' => (string) config("database.connections.{$dbConnection}.collation"),
                ],
                'redis' => [
                    'host' => (string) config('database.redis.default.host'),
                    'port' => (int) config('database.redis.default.port'),
                    'database' => (int) config('database.redis.default.database'),
                    'username' => SelfConnectionSettings::maskUsername((string) config('database.redis.default.username')),
                ],
            ],
            'meta' => $this->settings->read(),
        ]);
    }

    public function testDatabase(Request $request): JsonResponse
    {
        $data = $this->validateDatabase($request);
        $result = $this->testConnectionInternal('database', $data);

        $this->settings->appendAudit('database', 'test', $result['success'], [
            'masked_host' => SelfConnectionSettings::maskHost($data['host']),
            'error' => $result['error'] ?? null,
        ]);

        $this->settings->write(['database' => [
            'last_tested_at' => now()->toIso8601String(),
            'last_test_success' => $result['success'],
            'last_test_error' => $result['error'] ?? null,
            'masked_host' => SelfConnectionSettings::maskHost($data['host']),
            'masked_username' => SelfConnectionSettings::maskUsername($data['username']),
            'masked_database' => $data['database'],
        ]]);

        return response()->json($result);
    }

    public function testRedis(Request $request): JsonResponse
    {
        $data = $this->validateRedis($request);
        $result = $this->testConnectionInternal('redis', $data);

        $this->settings->appendAudit('redis', 'test', $result['success'], [
            'masked_host' => SelfConnectionSettings::maskHost($data['host']),
            'error' => $result['error'] ?? null,
        ]);

        $this->settings->write(['redis' => [
            'last_tested_at' => now()->toIso8601String(),
            'last_test_success' => $result['success'],
            'last_test_error' => $result['error'] ?? null,
            'masked_host' => SelfConnectionSettings::maskHost($data['host']),
            'masked_username' => SelfConnectionSettings::maskUsername($data['username'] ?? null),
        ]]);

        return response()->json($result);
    }

    public function applyDatabase(Request $request): JsonResponse
    {
        $data = $this->validateDatabase($request);
        $test = $this->testConnectionInternal('database', $data);

        if (! $test['success']) {
            return response()->json($test, 422);
        }

        if (! ($test['schema_ready'] ?? false) && ! $request->boolean('force_apply_without_schema')) {
            return response()->json([
                'success' => false,
                'needs_confirmation' => true,
                'message' => 'The target database does not appear to contain dpanel\'s tables yet. Applying now will likely break the app until you migrate your data there first.',
            ], 422);
        }

        $envPatch = [
            'DB_HOST' => $data['host'],
            'DB_PORT' => (string) $data['port'],
            'DB_DATABASE' => $data['database'],
            'DB_USERNAME' => $data['username'],
            'DB_PASSWORD' => (string) ($data['password'] ?? ''),
            'DB_CHARSET' => $data['charset'],
            'DB_COLLATION' => $data['collation'],
        ];

        if (! empty($data['ssl_ca_path'])) {
            $envPatch['MYSQL_ATTR_SSL_CA'] = $data['ssl_ca_path'];
        }

        $result = app(SelfConnectionOrchestrator::class)->apply($envPatch, fn () => $this->runVerify('database'));

        $this->settings->appendAudit('database', 'apply', $result['success'], [
            'masked_host' => SelfConnectionSettings::maskHost($data['host']),
            'error' => $result['error'] ?? null,
            'backup' => $result['backup'] ?? null,
        ]);

        $this->settings->write(['database' => [
            'last_applied_at' => now()->toIso8601String(),
            'last_applied_by' => $request->user()?->email,
            'last_applied_success' => $result['success'],
            'last_applied_error' => $result['error'] ?? null,
            'masked_host' => SelfConnectionSettings::maskHost($data['host']),
            'masked_username' => SelfConnectionSettings::maskUsername($data['username']),
            'masked_database' => $data['database'],
            'previous_env_backup_path' => $result['backup'] ?? null,
        ]]);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function applyRedis(Request $request): JsonResponse
    {
        $data = $this->validateRedis($request);
        $test = $this->testConnectionInternal('redis', $data);

        if (! $test['success']) {
            return response()->json($test, 422);
        }

        $envPatch = [
            'REDIS_HOST' => $data['host'],
            'REDIS_PORT' => (string) $data['port'],
            'REDIS_USERNAME' => (string) ($data['username'] ?? ''),
            'REDIS_PASSWORD' => (string) ($data['password'] ?? ''),
            'REDIS_DB' => (string) ($data['database'] ?? 0),
        ];

        $result = app(SelfConnectionOrchestrator::class)->apply($envPatch, fn () => $this->runVerify('redis'));

        $this->settings->appendAudit('redis', 'apply', $result['success'], [
            'masked_host' => SelfConnectionSettings::maskHost($data['host']),
            'error' => $result['error'] ?? null,
            'backup' => $result['backup'] ?? null,
        ]);

        $this->settings->write(['redis' => [
            'last_applied_at' => now()->toIso8601String(),
            'last_applied_by' => $request->user()?->email,
            'last_applied_success' => $result['success'],
            'last_applied_error' => $result['error'] ?? null,
            'masked_host' => SelfConnectionSettings::maskHost($data['host']),
            'masked_username' => SelfConnectionSettings::maskUsername($data['username'] ?? null),
            'previous_env_backup_path' => $result['backup'] ?? null,
        ]]);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDatabase(Request $request): array
    {
        return $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
            'charset' => ['nullable', 'string', 'max:32'],
            'collation' => ['nullable', 'string', 'max:64'],
            'driver' => ['nullable', 'in:mysql,mariadb'],
            'ssl_ca_path' => ['nullable', 'string', 'max:255'],
        ]) + [
            'charset' => (string) ($request->input('charset') ?: 'utf8mb4'),
            'collation' => (string) ($request->input('collation') ?: 'utf8mb4_unicode_ci'),
            'driver' => (string) ($request->input('driver') ?: 'mysql'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRedis(Request $request): array
    {
        return $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:255'],
            'database' => ['nullable', 'integer', 'min:0', 'max:15'],
            'tls' => ['nullable', 'boolean'],
        ]) + [
            'database' => (int) ($request->input('database') ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, error?: string, schema_ready?: bool}
     */
    private function testConnectionInternal(string $target, array $data): array
    {
        if ($target === 'database') {
            if (! empty($data['ssl_ca_path']) && ! is_readable($data['ssl_ca_path'])) {
                return ['success' => false, 'error' => 'SSL CA file is not readable on this server.'];
            }

            Config::set('database.connections.test_remote', [
                'driver' => $data['driver'] ?? 'mysql',
                'host' => $data['host'],
                'port' => $data['port'],
                'database' => $data['database'],
                'username' => $data['username'],
                'password' => $data['password'] ?? '',
                'charset' => $data['charset'] ?? 'utf8mb4',
                'collation' => $data['collation'] ?? 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'options' => ! empty($data['ssl_ca_path']) ? [\PDO::MYSQL_ATTR_SSL_CA => $data['ssl_ca_path']] : [],
            ]);

            try {
                DB::connection('test_remote')->getPdo();
                $schemaReady = Schema::connection('test_remote')->hasTable('sessions')
                    && Schema::connection('test_remote')->hasTable('migrations');

                return ['success' => true, 'schema_ready' => $schemaReady];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $this->sanitizeError($e->getMessage())];
            } finally {
                DB::purge('test_remote');
                Config::set('database.connections.test_remote', null);
            }
        }

        Config::set('database.redis.test_remote', [
            'host' => $data['host'],
            'port' => $data['port'],
            'username' => $data['username'] ?? null,
            'password' => $data['password'] ?? null,
            'database' => $data['database'] ?? 0,
            'scheme' => ! empty($data['tls']) ? 'tls' : 'tcp',
        ]);

        try {
            Redis::connection('test_remote')->ping();

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $this->sanitizeError($e->getMessage())];
        } finally {
            try {
                Redis::connection('test_remote')->disconnect();
            } catch (\Throwable) {
                // ignore
            }
            Config::set('database.redis.test_remote', null);
        }
    }

    /**
     * @return array{success: bool, error?: string}
     */
    private function runVerify(string $target): array
    {
        $result = $this->runPrivileged(['php', base_path('artisan'), 'selfconnection:verify', $target]);

        if ($result->successful()) {
            return ['success' => true];
        }

        $output = trim($result->errorOutput() ?: $result->output());

        return ['success' => false, 'error' => $output !== '' ? substr($output, -500) : 'Verification failed.'];
    }

    private function runPrivileged(array $command)
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return Process::timeout(30)->run($command);
        }

        return Process::timeout(30)->run(array_merge(['sudo', '-n'], $command));
    }

    private function sanitizeError(string $message): string
    {
        return trim(preg_replace('/password=\S+/i', 'password=***', $message) ?? $message);
    }
}
