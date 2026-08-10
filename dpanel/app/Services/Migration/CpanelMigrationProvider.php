<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CpanelMigrationProvider implements MigrationProvider
{
    public function key(): string { return 'cpanel'; }

    public function inspect(string $archivePath): array
    {
        return $this->request('inspect', ['archive_path' => $archivePath]);
    }

    public function restore(string $archivePath, array $selection): array
    {
        return $this->request('restore', ['archive_path' => $archivePath, 'selection' => $selection]);
    }

    private function request(string $action, array $payload): array
    {
        $response = Http::acceptJson()->asJson()
            ->withToken((string) config('serverpanel.execution_api_token'))
            ->timeout((int) config('serverpanel.execution_api_upload_timeout', 3600))
            ->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/migration/cpanel/'.$action, $payload);
        if (! $response->successful() || ! $response->json('success')) {
            throw new RuntimeException((string) ($response->json('message') ?: 'cPanel migration API failed.'));
        }
        return (array) $response->json('data', []);
    }
}
