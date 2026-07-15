<?php

namespace App\Services\PhpMyAdmin;

use App\Models\DatabaseRequest;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
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

    public function listDatabases(?string $onlyDatabase = null): array
    {
        $onlyDatabase = $onlyDatabase !== null && trim($onlyDatabase) !== ''
            ? $this->assertSafeIdentifier($onlyDatabase)
            : '';

        if ($this->connection()->getDriverName() === 'sqlite') {
            $rows = $this->connection()->select('PRAGMA database_list');

            $databases = collect($rows)
                ->map(fn ($row): string => (string) ($row->name ?? ''))
                ->filter(static fn (string $name): bool => $name !== '')
                ->values();

            if ($onlyDatabase !== '') {
                $databases = $databases->filter(static fn (string $name): bool => $name === $onlyDatabase);
            }

            return $databases->values()->all();
        }

        if ($onlyDatabase !== '') {
            $rows = $this->connection()->select(
                'SELECT schema_name AS name
                 FROM information_schema.schemata
                 WHERE schema_name = ?
                 ORDER BY schema_name',
                [$onlyDatabase]
            );
        } else {
            $rows = $this->connection()->select(
                'SELECT
                    schema_name AS name
                FROM information_schema.schemata
                ORDER BY schema_name'
            );
        }

        return collect($rows)
            ->map(fn ($row): string => (string) ($row->name ?? ''))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function listDatabasesForUser(?User $user, ?string $onlyDatabase = null, bool $allowAllAccess = false): array
    {
        $allDatabases = $this->listDatabases();
        if ($allowAllAccess && $this->canAccessAllDatabases($user)) {
            if ($onlyDatabase !== null && trim($onlyDatabase) !== '') {
                $onlyDatabase = $this->assertSafeIdentifier($onlyDatabase);
                return array_values(array_filter($allDatabases, static fn (string $name): bool => $name === $onlyDatabase));
            }

            return $allDatabases;
        }

        if ($user === null) {
            return [];
        }

        $assigned = DatabaseRequest::query()
            ->where('assigned_user_id', $user->id)
            ->pluck('database_name')
            ->map(static fn ($name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->map(fn (string $name): string => $this->assertSafeIdentifier($name))
            ->unique()
            ->values()
            ->all();

        $filtered = array_values(array_intersect($allDatabases, $assigned));

        if ($onlyDatabase !== null && trim($onlyDatabase) !== '') {
            $onlyDatabase = $this->assertSafeIdentifier($onlyDatabase);
            $filtered = array_values(array_filter($filtered, static fn (string $name): bool => $name === $onlyDatabase));
        }

        return $filtered;
    }

    public function firstAccessibleDatabase(?User $user, bool $allowAllAccess = false): string
    {
        return $this->listDatabasesForUser($user, null, $allowAllAccess)[0] ?? '';
    }

    public function canAccessDatabase(?User $user, string $database, bool $allowAllAccess = false): bool
    {
        $database = $this->assertSafeIdentifier($database);

        if ($database === '') {
            return false;
        }

        if ($allowAllAccess && $this->canAccessAllDatabases($user)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return DatabaseRequest::query()
            ->where('assigned_user_id', $user->id)
            ->where('database_name', $database)
            ->exists();
    }

    public function resolveAccessibleDatabase(?User $user, ?string $requestedDatabase = null, bool $allowAllAccess = false): string
    {
        $requestedDatabase = $requestedDatabase !== null && trim($requestedDatabase) !== ''
            ? $this->assertSafeIdentifier($requestedDatabase)
            : '';

        if ($requestedDatabase !== '' && $this->canAccessDatabase($user, $requestedDatabase, $allowAllAccess)) {
            return $requestedDatabase;
        }

        return $this->firstAccessibleDatabase($user, $allowAllAccess);
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

    public function renameTable(string $database, string $table, string $newTable): void
    {
        $database = $this->assertSafeIdentifier($database);
        $table = $this->assertSafeIdentifier($table);
        $newTable = $this->assertSafeIdentifier($newTable);

        $currentQualified = $this->qualifyTable($database, $table);
        $newQualified = $this->qualifyTable($database, $newTable);

        $this->connection()->statement('RENAME TABLE '.$currentQualified.' TO '.$newQualified);
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     */
    public function createTable(string $database, string $table, array $columns = []): void
    {
        $database = $this->assertSafeIdentifier($database);
        $table = $this->assertSafeIdentifier($table);
        $normalizedColumns = $this->normalizeSchemaColumns($columns, true);
        if ($normalizedColumns === []) {
            $normalizedColumns = $this->normalizeSchemaColumns([
                [
                    'name' => 'id',
                    'type' => 'BIGINT',
                    'length' => '',
                    'nullable' => false,
                    'defaultValue' => '',
                    'unsigned' => true,
                    'autoIncrement' => true,
                    'primaryKey' => true,
                ],
            ], true);
        }

        $this->assertUniqueColumnNames($normalizedColumns);

        $definitions = [];
        $primaryKeys = [];
        foreach ($normalizedColumns as $column) {
            $definitions[] = $this->buildColumnDefinitionSql($column, true);
            if ($column['primaryKey']) {
                $primaryKeys[] = $column['name'];
            }
        }

        if ($primaryKeys !== []) {
            $definitions[] = 'PRIMARY KEY ('.$this->joinQuotedIdentifiers($primaryKeys).')';
        }

        $sql = sprintf(
            'CREATE TABLE %s (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            $this->qualifyTable($database, $table),
            implode(', ', $definitions)
        );

        $this->connection()->statement($sql);
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     */
    public function alterTableStructure(string $database, string $table, array $columns = []): void
    {
        $database = $this->assertSafeIdentifier($database);
        $table = $this->assertSafeIdentifier($table);

        $normalizedColumns = $this->normalizeSchemaColumns($columns, false);
        if ($normalizedColumns === []) {
            throw new InvalidArgumentException('No column definitions were provided.');
        }

        $this->assertUniqueColumnNames($normalizedColumns);

        $clauses = [];
        foreach ($normalizedColumns as $column) {
            if ($column['remove']) {
                if ($column['originalName'] === '') {
                    continue;
                }

                $clauses[] = 'DROP COLUMN '.$this->quoteIdentifier($column['originalName']);
                continue;
            }

            $definition = $this->buildColumnDefinitionSql($column, false);
            $originalName = $column['originalName'] !== '' ? $column['originalName'] : $column['name'];

            if ($column['originalName'] === '' || $column['originalName'] !== $column['name']) {
                $clauses[] = 'CHANGE COLUMN '.$this->quoteIdentifier($originalName).' '.$definition;
            } else {
                $clauses[] = 'MODIFY COLUMN '.$definition;
            }
        }

        if ($clauses === []) {
            throw new InvalidArgumentException('No changes detected.');
        }

        $sql = sprintf(
            'ALTER TABLE %s %s',
            $this->qualifyTable($database, $table),
            implode(', ', $clauses)
        );

        $this->connection()->statement($sql);
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSchemaColumns(array $columns, bool $isCreate): array
    {
        return collect($columns)
            ->map(function ($column, $index) use ($isCreate): array {
                $name = $this->assertSafeIdentifier((string) ($column['name'] ?? ''));
                $originalName = $this->assertSafeIdentifier((string) ($column['originalName'] ?? $name));
                $type = strtoupper(trim((string) ($column['type'] ?? 'VARCHAR')));
                $length = trim((string) ($column['length'] ?? ''));
                $nullable = (bool) ($column['nullable'] ?? false);
                $defaultValue = trim((string) ($column['defaultValue'] ?? ''));
                $unsigned = (bool) ($column['unsigned'] ?? false);
                $autoIncrement = (bool) ($column['autoIncrement'] ?? false);
                $primaryKey = (bool) ($column['primaryKey'] ?? false);
                $comment = trim((string) ($column['comment'] ?? ''));
                $after = trim((string) ($column['after'] ?? ''));
                $remove = (bool) ($column['remove'] ?? false);

                if ($name === '') {
                    throw new InvalidArgumentException('Column name is required.');
                }

                return [
                    'name' => $name,
                    'originalName' => $originalName,
                    'type' => $this->normalizeColumnType($type),
                    'length' => $length,
                    'nullable' => $nullable,
                    'defaultValue' => $defaultValue,
                    'unsigned' => $unsigned,
                    'autoIncrement' => $autoIncrement,
                    'primaryKey' => $primaryKey,
                    'comment' => $comment,
                    'after' => $after,
                    'remove' => $remove,
                    '_index' => $index,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     */
    private function assertUniqueColumnNames(array $columns): void
    {
        $names = [];
        foreach ($columns as $column) {
            if (! empty($column['remove'])) {
                continue;
            }

            $name = (string) ($column['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException('Duplicate column names are not allowed.');
            }

            $names[] = $name;
        }
    }

    /**
     * @param array<string, mixed> $column
     */
    private function buildColumnDefinitionSql(array $column, bool $allowAfter = false): string
    {
        $name = $this->assertSafeIdentifier((string) ($column['name'] ?? ''));
        $type = $this->normalizeColumnType((string) ($column['type'] ?? 'VARCHAR'));
        $length = trim((string) ($column['length'] ?? ''));
        $nullable = (bool) ($column['nullable'] ?? false);
        $defaultValue = trim((string) ($column['defaultValue'] ?? ''));
        $unsigned = (bool) ($column['unsigned'] ?? false);
        $autoIncrement = (bool) ($column['autoIncrement'] ?? false);
        $comment = trim((string) ($column['comment'] ?? ''));
        $after = trim((string) ($column['after'] ?? ''));

        $sqlType = $type;
        if (in_array($type, ['VARCHAR', 'CHAR', 'VARBINARY', 'BINARY'], true)) {
            $sqlType .= '('.($length !== '' ? $length : '255').')';
        } elseif (in_array($type, ['DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE'], true)) {
            $sqlType .= '('.($length !== '' ? $length : '10,2').')';
        }

        $parts = [
            $this->quoteIdentifier($name).' '.$sqlType,
        ];

        if ($unsigned && in_array($type, ['INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'MEDIUMINT', 'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE'], true)) {
            $parts[] = 'UNSIGNED';
        }

        $parts[] = $nullable ? 'NULL' : 'NOT NULL';

        if ($defaultValue !== '') {
            $parts[] = 'DEFAULT '.$this->quoteSqlValue($defaultValue);
        }

        if ($autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        if ($comment !== '') {
            $parts[] = 'COMMENT '.$this->quoteSqlValue($comment);
        }

        if ($allowAfter && $after !== '') {
            $parts[] = 'AFTER '.$this->quoteIdentifier($this->assertSafeIdentifier($after));
        }

        return implode(' ', $parts);
    }

    private function normalizeColumnType(string $type): string
    {
        $type = strtoupper(trim($type));
        $allowed = [
            'INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'MEDIUMINT',
            'DECIMAL', 'NUMERIC', 'FLOAT', 'DOUBLE',
            'VARCHAR', 'CHAR', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'BLOB',
            'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'JSON', 'BOOLEAN',
            'VARBINARY', 'BINARY',
        ];

        if (! in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported column type: '.$type);
        }

        return $type;
    }

    private function quoteSqlValue(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param array<int, string> $identifiers
     */
    private function joinQuotedIdentifiers(array $identifiers): string
    {
        return implode(', ', array_map(fn (string $identifier): string => $this->quoteIdentifier($this->assertSafeIdentifier($identifier)), $identifiers));
    }

    /**
     * @return array<string, mixed>
     */
    public function tableDetails(string $database, string $table, int $page = 1, int $perPage = 25, string $sortColumn = '', string $sortDirection = 'asc'): array
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

        $availableColumns = array_values(array_filter(array_map(
            static fn (array $column): string => (string) ($column['name'] ?? ''),
            $columns
        )));
        $requestedSortColumn = trim($sortColumn);
        if ($requestedSortColumn !== '') {
            $requestedSortColumn = $this->assertSafeIdentifier($requestedSortColumn);
        }
        $requestedSortDirection = strtoupper(trim($sortDirection)) === 'DESC' ? 'DESC' : 'ASC';
        $orderColumn = '';

        if ($requestedSortColumn !== '' && in_array($requestedSortColumn, $availableColumns, true)) {
            $orderColumn = $requestedSortColumn;
        } elseif ($primaryKeys !== []) {
            $orderColumn = $primaryKeys[0];
        }

        $orderBy = $orderColumn !== ''
            ? ' ORDER BY '.$this->quoteIdentifier($orderColumn).' '.$requestedSortDirection
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
        $restoreDatabase = null;

        if ($database !== null && $database !== '') {
            $restoreDatabase = $this->activeDatabase($connection);
            $connection->statement('USE '.$this->quoteIdentifier((string) $database));
        }

        $started = microtime(true);

        try {
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
        } finally {
            if ($restoreDatabase !== null && $restoreDatabase !== '' && $restoreDatabase !== $database) {
                $connection->statement('USE '.$this->quoteIdentifier($restoreDatabase));
            }
        }
    }

    /**
     * @return array{path: string, filename: string, database: string}
     */
    /**
     * @return array{path: string, filename: string, database: string, table: string}
     */
    public function exportDatabase(?string $database = null, ?string $table = null): array
    {
        $connection = $this->connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Export is only supported for MySQL and MariaDB.');
        }

        $databaseName = $database !== null && trim($database) !== ''
            ? $this->assertSafeIdentifier($database)
            : $this->currentDatabase();

        $databaseName = $this->assertSafeIdentifier($databaseName);
        $tableName = $table !== null && trim($table) !== ''
            ? $this->assertSafeIdentifier($table)
            : '';
        $config = $this->databaseConnectionConfig();
        $mysqldumpPath = $this->resolveMysqlClientBinary('mysqldump', $driver);
        $targetDir = storage_path('app/phpmyadmin-exports');
        File::ensureDirectoryExists($targetDir);

        $filename = $tableName !== ''
            ? sprintf('%s-%s-%s.sql', $databaseName, $tableName, now()->format('Ymd_His'))
            : sprintf('%s-%s.sql', $databaseName, now()->format('Ymd_His'));
        $targetPath = $targetDir.DIRECTORY_SEPARATOR.$filename;

        $arguments = [
            $mysqldumpPath,
            '--single-transaction',
            '--skip-lock-tables',
            '--column-statistics=0',
            '--routines',
            '--events',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? '3306'),
            '--user='.(string) ($config['username'] ?? ''),
            $databaseName,
        ];

        if ($tableName !== '') {
            $arguments[] = $tableName;
        }

        $password = (string) ($config['password'] ?? '');
        $socket = (string) ($config['unix_socket'] ?? '');

        if ($password !== '') {
            $arguments[] = '--password='.$password;
        }

        if ($socket !== '') {
            $arguments[] = '--socket='.$socket;
        }

        $result = Process::timeout(600)->run($arguments);
        if (! $result->successful()) {
            throw new InvalidArgumentException(trim($result->errorOutput().' '.$result->output()) ?: 'Database export failed.');
        }

        File::put($targetPath, $result->output());

        return [
            'path' => $targetPath,
            'filename' => $filename,
            'database' => $databaseName,
            'table' => $tableName,
        ];
    }

    /**
     * @return array{database: string, output: string}
     */
    public function importDatabase(string $database, string $sql): array
    {
        $connection = $this->connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Import is only supported for MySQL and MariaDB.');
        }

        $databaseName = $this->assertSafeIdentifier($database);
        $sql = trim($sql);
        if ($sql === '') {
            throw new InvalidArgumentException('Import file is empty.');
        }

        $config = $this->databaseConnectionConfig();
        $mysqlPath = $this->resolveMysqlClientBinary('mysql', $driver);
        $arguments = [
            $mysqlPath,
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? '3306'),
            '--user='.(string) ($config['username'] ?? ''),
            '--default-character-set=utf8mb4',
            $databaseName,
        ];

        $password = (string) ($config['password'] ?? '');
        $socket = (string) ($config['unix_socket'] ?? '');

        if ($password !== '') {
            $arguments[] = '--password='.$password;
        }

        if ($socket !== '') {
            $arguments[] = '--socket='.$socket;
        }

        $result = Process::timeout(600)->input($sql)->run($arguments);
        if (! $result->successful()) {
            throw new InvalidArgumentException(trim($result->errorOutput().' '.$result->output()) ?: 'Database import failed.');
        }

        return [
            'database' => $databaseName,
            'output' => trim($result->output()),
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

    /**
     * @return array<string, mixed>
     */
    private function databaseConnectionConfig(): array
    {
        $driver = $this->connection()->getDriverName();
        $configKey = $driver === 'mariadb' ? 'mariadb' : 'mysql';

        return (array) config('database.connections.'.$configKey, []);
    }

    private function resolveMysqlClientBinary(string $binary, ?string $preferredFamily = null): string
    {
        $binary = strtolower(trim($binary));
        if (! in_array($binary, ['mysql', 'mysqldump'], true)) {
            throw new InvalidArgumentException('Unsupported MySQL client binary.');
        }

        $envKey = $binary === 'mysqldump' ? 'MYSQLDUMP_PATH' : 'MYSQL_PATH';
        $candidate = trim((string) env($envKey, ''));
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }

        $commonRoots = [];

        if (PHP_OS_FAMILY === 'Windows') {
            $preferredFamily = strtolower(trim((string) $preferredFamily));
            $families = $preferredFamily === 'mariadb'
                ? ['mariadb', 'mysql']
                : ['mysql', 'mariadb'];

            foreach ($families as $family) {
                foreach (['D:\\wamp64\\bin', 'C:\\wamp64\\bin'] as $basePath) {
                    $commonRoots[] = $basePath.DIRECTORY_SEPARATOR.$family;
                }
            }
        }

        foreach ($commonRoots as $root) {
            foreach ($this->globClientCandidates($root, $binary) as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return $binary;
    }

    /**
     * @return array<int, string>
     */
    private function globClientCandidates(string $root, string $binary): array
    {
        $patterns = [
            $root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.$binary.'.exe',
        ];

        $matches = [];
        foreach ($patterns as $pattern) {
            $found = glob($pattern);
            if (is_array($found)) {
                $matches = array_merge($matches, $found);
            }
        }

        return $matches;
    }

    private function activeDatabase(ConnectionInterface $connection): string
    {
        if ($connection->getDriverName() === 'sqlite') {
            return (string) config('database.connections.sqlite.database', ':memory:');
        }

        try {
            $row = $connection->selectOne('SELECT DATABASE() AS database');

            return (string) ($row->database ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function assertSafeIdentifier(string $value): string
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }

        return $value;
    }

    public function canAccessAllDatabases(?User $user): bool
    {
        return (int) ($user?->id ?? 0) === 1;
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
