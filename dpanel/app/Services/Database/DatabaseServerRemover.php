<?php

namespace App\Services\Database;

use App\Models\DatabaseRequest;
use App\Services\MongoAdminClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Drops a database from its server (MariaDB, PostgreSQL or MongoDB), and its
 * user unless another of the panel's databases still uses that user. Only
 * runs when an admin ticked "also delete the database" when deleting.
 * dRust refuses system databases, the panel's own database and root.
 */
class DatabaseServerRemover
{
    public function __construct(private readonly MongoAdminClient $mongo) {}

    /** @return string what was dropped */
    public function drop(DatabaseRequest $database): string
    {
        $name = (string) $database->database_name;
        $user = (string) $database->database_user;
        if ($name === '') {
            throw new RuntimeException('The database has no name.');
        }
        $userShared = $user === '' || DatabaseRequest::query()
            ->where('database_user', $user)
            ->where('engine', $database->engine)
            ->whereKeyNot($database->id)
            ->exists();

        return $database->isMongodb()
            ? $this->dropMongo($name, $user, ! $userShared)
            : $this->dropSql($database, ! $userShared);
    }

    /**
     * Makes MariaDB databases and users from a trash archive's metadata again
     * (create is idempotent: an existing one only gets its password reset).
     *
     * @param  list<array<string, mixed>>  $databases  name, user, password, host, charset, collation
     */
    public function recreate(array $databases): void
    {
        foreach ($databases as $database) {
            if (! is_array($database) || ($database['engine'] ?? DatabaseRequest::ENGINE_MARIADB) !== DatabaseRequest::ENGINE_MARIADB || empty($database['name']) || empty($database['user']) || empty($database['password'])) {
                continue;
            }
            $this->send([
                'action' => 'create',
                'engine' => 'mariadb',
                'database_name' => (string) $database['name'],
                'database_user' => (string) ($database['user'] ?? ''),
                'database_password' => (string) ($database['password'] ?? ''),
                'database_host' => (string) (($database['host'] ?? '') ?: '127.0.0.1'),
                'charset' => (string) (($database['charset'] ?? '') ?: 'utf8mb4'),
                'collation' => (string) (($database['collation'] ?? '') ?: 'utf8mb4_unicode_ci'),
            ]);
        }
    }

    private function dropSql(DatabaseRequest $database, bool $withUser): string
    {
        return $this->send([
            'action' => $withUser ? 'drop' : 'drop_database',
            'engine' => $database->isPostgresql() ? 'postgresql' : 'mariadb',
            'database_name' => (string) $database->database_name,
            'database_user' => (string) $database->database_user,
            'database_password' => '',
            'database_host' => (string) ($database->database_host ?: '127.0.0.1'),
        ]);
    }

    /** @param array<string, string> $payload one dRust /database-request call */
    private function send(array $payload): string
    {
        $apiUrl = trim((string) config('serverpanel.database_api_url', ''));
        if ($apiUrl === '') {
            throw new RuntimeException('Database API is not configured. Set SERVERPANEL_DATABASE_API_URL.');
        }
        $request = Http::acceptJson()->asJson()->timeout((int) config('serverpanel.execution_api_timeout', 60));
        $token = trim((string) config('serverpanel.database_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($apiUrl, $payload);
        $json = (array) $response->json();
        if (! $response->successful() || ! ($json['success'] ?? false)) {
            throw new RuntimeException((string) ($json['message'] ?? $response->body() ?: 'dRust database request failed.'));
        }

        return (string) ($json['message'] ?? 'Done.');
    }

    private function dropMongo(string $name, string $user, bool $withUser): string
    {
        $dropped = $this->mongo->run(['action' => 'drop_database', 'db' => $name]);
        if (! $dropped['success']) {
            throw new RuntimeException($dropped['message'] ?: 'MongoDB could not drop the database.');
        }
        if ($withUser && $user !== '') {
            // Users live in their own database (provision_user), so dropping it
            // leaves the user behind.
            $this->mongo->run(['action' => 'command', 'db' => $name, 'command' => ['dropUser' => $user]]);
        }

        return $withUser ? "MongoDB database and user dropped: {$name} / {$user}" : "MongoDB database dropped: {$name}";
    }
}
