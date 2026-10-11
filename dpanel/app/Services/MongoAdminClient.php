<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Sends Mongo Admin operations to drust, which runs them with the MongoDB
 * admin login from config/mongodb.php. The browser never sees that login.
 */
class MongoAdminClient
{
    /** Operations the Mongo Admin page may run. */
    public const ACTIONS = [
        'overview', 'create_collection', 'drop_collection', 'rename_collection', 'drop_database',
        'find', 'insert', 'replace', 'delete', 'delete_many',
        'indexes', 'create_index', 'drop_index', 'aggregate', 'command', 'export',
    ];

    /**
     * @param  array<string, mixed>  $op
     * @return array{success: bool, message: string, data: mixed}
     */
    public function run(array $op): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            return $this->failure('drust API is not configured.');
        }
        if ((string) config('mongodb.admin_password') === '') {
            return $this->failure('No MongoDB admin password is saved. Run: sudo dpanel mongodb install');
        }

        $request = Http::acceptJson()->asJson()->timeout(120);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post(rtrim($baseUrl, '/').'/api/v1/mongodb/admin', [
                'connection' => [
                    'host' => (string) config('mongodb.host'),
                    'port' => (int) config('mongodb.port'),
                    'username' => (string) config('mongodb.admin_username'),
                    'password' => (string) config('mongodb.admin_password'),
                ],
                'op' => $op,
            ]);
        } catch (\Throwable $e) {
            return $this->failure('drust API request failed: '.$e->getMessage());
        }

        $json = $response->json();
        if (! is_array($json)) {
            return $this->failure($response->body() ?: 'drust API failed.');
        }

        return [
            'success' => (bool) ($json['success'] ?? false),
            'message' => (string) ($json['message'] ?? ''),
            'data' => $json['data'] ?? null,
        ];
    }

    /**
     * @return array{success: bool, message: string, data: mixed}
     */
    private function failure(string $message): array
    {
        return ['success' => false, 'message' => $message, 'data' => null];
    }
}
