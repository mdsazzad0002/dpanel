<?php

namespace App\Services\PhpMyAdmin;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatabaseAdminService
{
    /**
     * @return array<string, mixed>
     */
    public function serverInfo(): array
    {
        $connection = $this->connection();

        return [
            'driver' => $connection->getDriverName(),
            'host' => (string) config('database.connections.mysql.host', config('database.connections.mariadb.host', '127.0.0.1')),
            'port' => (string) config('database.connections.mysql.port', config('database.connections.mariadb.port', '3306')),
            'database' => (string) config('database.connections.mysql.database', config('database.connections.mariadb.database', '')),
            'username' => (string) config('database.connections.mysql.username', config('database.connections.mariadb.username', '')),
            'version' => $this->serverVersion(),

        ];
    }

    public function listDatabases(): array
    {
        if ($this->connection()->getDriverName() === 'sqlite') {
            $rows = $this->connection()->select('PRAGMA database_list');

            return collect($rows)
                ->map(fn ($row): string => (string) ($row->name ?? ''))
                ->filter(static fn (string $name): bool => $name !== '')
                ->values()
                ->all();
        }

        $rows = $this->connection()->select(
            'SELECT
                schema_name AS name
            FROM information_schema.schemata
            ORDER BY schema_name'
        );

        return collect($rows)
            ->map(fn ($row): string => (string) ($row->name ?? ''))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }

    public function currentDatabase(): string
    {
        $driver = $this->connection()->getDriverName();

        if ($driver === 'sqlite') {
            return (string) config('database.connections.sqlite.database', ':memory:');
        }

        return (string) config('database.connections.mysql.database', config('database.connections.mariadb.database', ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function databaseSummary(string $database): array
    {
        $database = $this->assertSafeIdentifier($database);

        if (! $this->supportsCatalogQueries()) {
            return [
                'name' => $database,
                'tables_count' => 0,
                'views_count' => 0,
                'size_bytes' => 0,
                'estimated_rows' => 0,
            ];
        }

        $row = $this->connection()->selectOne(
            'SELECT
                COUNT(*) AS tables_count,
                COALESCE(SUM(CASE WHEN table_type = "BASE TABLE" THEN 1 ELSE 0 END), 0) AS base_tables_count,
                COALESCE(SUM(CASE WHEN table_type = "VIEW" THEN 1 ELSE 0 END), 0) AS views_count,
                COALESCE(SUM(data_length + index_length), 0) AS size_bytes,
                COALESCE(SUM(table_rows), 0) AS estimated_rows
            FROM information_schema.tables
            WHERE table_schema = ?',
            [$database]
        );

        return [
            'name' => $database,
            'tables_count' => (int) ($row->tables_count ?? 0),
            'base_tables_count' => (int) ($row->base_tables_count ?? 0),
            'views_count' => (int) ($row->views_count ?? 0),
            'size_bytes' => (int) ($row->size_bytes ?? 0),
            'estimated_rows' => (int) ($row->estimated_rows ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTables(string $database): array
    {
        $database = $this->assertSafeIdentifier($database);

        if (! $this->supportsCatalogQueries()) {
            return [];
        }

        $rows = $this->connection()->select(
            'SELECT
                table_name AS name,
                table_type AS type,
                engine,
                table_rows AS estimated_rows,
                data_length + index_length AS size_bytes,
                table_collation AS collation,
                table_comment AS comment
            FROM information_schema.tables
            WHERE table_schema = ?
            ORDER BY table_name',
            [$database]
        );

        return collect($rows)->map(fn ($row): array => [
            'name' => (string) ($row->name ?? ''),
            'type' => (string) ($row->type ?? ''),
            'engine' => $row->engine ?? null,
            'estimated_rows' => (int) ($row->estimated_rows ?? 0),
            'size_bytes' => (int) ($row->size_bytes ?? 0),
            'collation' => $row->collation ?? null,
            'comment' => $row->comment ?? null,
        ])->all();
    }

    public function dropTable(string $database, string $table): void
    {
        $database = $this->assertSafeIdentifier($database);
        $table = $this->assertSafeIdentifier($table);

        $qualifiedTable = $this->qualifyTable($database, $table);

        $this->connection()->statement('DROP TABLE '.$qualifiedTable);
    }

    public function truncateTable(string $database, string $table): void
    {
        $database = $this->assertSafeIdentifier($database);
        $table = $this->assertSafeIdentifier($table);

        $qualifiedTable = $this->qualifyTable($database, $table);

        $this->connection()->statement('TRUNCATE TABLE '.$qualifiedTable);
    }

    /**
     * @return array<string, mixed>
     */
    public function tableDetails(string $database, string $table, int $page = 1, int $perPage = 25): array
    {
        $database = $this->assertSafeIdentifier($database);
        $table = $this->assertSafeIdentifier($table);
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));

        $columns = $this->describeTable($database, $table);
        $primaryKeys = array_values(array_filter(
            array_map(
                static fn (array $column): ?string => ($column['is_primary'] ?? false) ? (string) $column['name'] : null,
                $columns
            )
        ));

        $qualifiedTable = $this->qualifyTable($database, $table);
        $offset = ($page - 1) * $perPage;
        $orderBy = $primaryKeys !== []
            ? ' ORDER BY '.$this->quoteIdentifier($primaryKeys[0])
            : '';

        $totalRow = $this->connection()->selectOne('SELECT COUNT(*) AS aggregate FROM '.$qualifiedTable);
        $rows = $this->connection()->select('SELECT * FROM '.$qualifiedTable.$orderBy.' LIMIT '.$perPage.' OFFSET '.$offset);

        $normalizedRows = collect($rows)->map(static fn ($row): array => (array) $row)->all();
        $total = (int) ($totalRow->aggregate ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'database' => $database,
            'table' => $table,
            'columns' => $columns,
            'rows' => $normalizedRows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
                'has_previous' => $page > 1,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executeSql(string $sql, ?string $database = null): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new InvalidArgumentException('SQL cannot be empty.');
        }

        $normalized = rtrim($sql, " \t\n\r\0\x0B;");
        if (str_contains($normalized, ';')) {
            throw new InvalidArgumentException('Only one SQL statement can be executed at a time.');
        }

        if ($database !== null && $database !== '') {
            $this->assertSafeIdentifier($database);
        }

        $connection = $this->connection();
        $started = microtime(true);
        $keyword = strtolower(strtok(ltrim($normalized), " \t\n\r\0\x0B") ?: '');

        if (in_array($keyword, ['select', 'show', 'describe', 'desc', 'explain', 'with'], true)) {
            $rows = $connection->select($normalized);
            $data = collect($rows)->map(static fn ($row): array => (array) $row)->all();
            $columns = $data !== [] ? array_keys($data[0]) : [];

            return [
                'mode' => 'result',
                'columns' => $columns,
                'rows' => $data,
                'affected_rows' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }

        $affectedRows = $connection->affectingStatement($normalized);

        return [
            'mode' => 'statement',
            'columns' => [],
            'rows' => [],
            'affected_rows' => $affectedRows,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function describeTable(string $database, string $table): array
    {
        $database = $this->assertSafeIdentifier($database);
        $table = $this->assertSafeIdentifier($table);

        if (! $this->supportsCatalogQueries()) {
            return [];
        }

        $rows = $this->connection()->select(
            'SELECT
                column_name AS name,
                column_type AS type,
                is_nullable AS is_nullable,
                column_default AS default_value,
                extra,
                column_key,
                ordinal_position AS position,
                character_set_name AS charset,
                collation_name AS collation
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            ORDER BY ordinal_position',
            [$database, $table]
        );

        return collect($rows)->map(fn ($row): array => [
            'name' => (string) ($row->name ?? ''),
            'type' => (string) ($row->type ?? ''),
            'is_nullable' => (string) ($row->is_nullable ?? 'YES'),
            'default_value' => $row->default_value ?? null,
            'extra' => $row->extra ?? null,
            'key' => $row->column_key ?? null,
            'position' => (int) ($row->position ?? 0),
            'charset' => $row->charset ?? null,
            'collation' => $row->collation ?? null,
            'is_primary' => strtoupper((string) ($row->column_key ?? '')) === 'PRI',
        ])->all();
    }


    public function supportsCatalogQueries(): bool
    {
        return in_array($this->connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function serverVersion(): string
    {
        try {
            $row = $this->connection()->selectOne('SELECT VERSION() AS version');

            return (string) ($row->version ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    public function connection(): ConnectionInterface
    {
        return DB::connection();
    }

    private function assertSafeIdentifier(string $value): string
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }

        return $value;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function qualifyTable(string $database, string $table): string
    {
        return $this->quoteIdentifier($database).'.'.$this->quoteIdentifier($table);
    }
}
