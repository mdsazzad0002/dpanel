<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GenericWebsiteMigrationProvider
{
    public function restore(array $payload): array
    {
        $response = Http::acceptJson()->asJson()->withToken((string) config('serverpanel.execution_api_token'))
            ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
            ->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/migration/generic/restore', $payload);
        if (! $response->successful() || ! $response->json('success')) throw new RuntimeException((string) ($response->json('message') ?: 'Generic migration failed.'));
        $result = (array) $response->json('data', []);
        if (! empty($payload['sql_path']) && ! empty($result['framework']) && ! empty($result['config_path'])) {
            $config = Http::acceptJson()->asJson()->withToken((string) config('serverpanel.execution_api_token'))->timeout(60)
                ->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/database-config', [
                    'site_owner' => $payload['site_owner'], 'framework' => $result['framework'], 'config_path' => $result['config_path'],
                    'database_name' => $payload['database_name'], 'database_user' => $payload['database_user'],
                    'database_password' => $payload['database_password'], 'database_host' => $payload['database_host'], 'database_port' => $payload['database_port'],
                ]);
            if (! $config->successful() || ! $config->json('success')) throw new RuntimeException((string) ($config->json('message') ?: 'Files restored, but project database configuration could not be connected.'));
        }
        return $result;
    }
}
