<?php

namespace App\Services;

use App\Models\DatabaseRequest;
use Illuminate\Support\Facades\Http;

/**
 * Signs the browser into pgAdmin. drust prepares a pgAdmin identity with one
 * saved server and returns a one-time URL (valid for a minute) that the edge
 * gateway turns into a pgAdmin session. See drust/src/pgadmin_sso.rs.
 */
class PgAdminSsoService
{
    /**
     * pgAdmin connected as this database's own role, showing only that database.
     */
    public function urlForDatabase(DatabaseRequest $database): string
    {
        return $this->request([
            'target' => 'database',
            'database_name' => (string) $database->database_name,
            'database_user' => (string) $database->database_user,
            'database_password' => (string) $database->database_password,
            'port' => (int) config('postgresql.port', 5432),
        ]);
    }

    /**
     * pgAdmin connected as the PostgreSQL superuser.
     */
    public function urlForAdmin(): string
    {
        $password = (string) config('postgresql.admin_password', '');
        if ($password === '') {
            throw new \RuntimeException('PostgreSQL admin password is not configured (PGSQL_ADMIN_PASSWORD).');
        }

        return $this->request([
            'target' => 'admin',
            'database_user' => (string) config('postgresql.admin_username', 'postgres'),
            'database_password' => $password,
            'port' => (int) config('postgresql.port', 5432),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(array $payload): string
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust API is not configured.');
        }

        // Each call boots pgAdmin's CLI twice, which takes several seconds.
        $request = Http::acceptJson()->asJson()->timeout(60);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post(rtrim($baseUrl, '/').'/api/v1/postgresql/pgadmin-login', $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('drust API request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $url = (string) ($json['data']['url'] ?? '');
        if (! $response->successful() || ! ($json['success'] ?? false) || ! str_starts_with($url, '/pgadmin4/')) {
            throw new \RuntimeException((string) ($json['message'] ?? 'pgAdmin sign-in failed.'));
        }

        return $url;
    }
}
