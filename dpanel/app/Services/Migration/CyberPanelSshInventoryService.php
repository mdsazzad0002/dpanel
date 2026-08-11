<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CyberPanelSshInventoryService implements RemotePanelInventoryProvider
{
    public function discover(array $credentials): array
    {
        return $this->request('discover', $credentials, 180);
    }

    /** @return array{path:string,name:string,mime:string} */
    public function download(array $credentials, string $type, ?string $sourcePath, ?string $database): array
    {
        $directory = storage_path('app/migrations/downloads');
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not prepare migration download storage.');
        }

        $extension = $type === 'files' ? 'tar.gz' : 'sql';
        $destination = $directory.'/'.Str::uuid().'.'.$extension;
        try {
            $result = $this->request('transfer', $credentials + [
                'type' => $type,
                'source_path' => $sourcePath,
                'database' => $database,
                'destination' => $destination,
            ], (int) config('serverpanel.execution_api_upload_timeout', 3600));
        } catch (\Throwable $exception) {
            @unlink($destination);
            throw $exception;
        }

        if (! is_file($destination) || filesize($destination) < 1) {
            throw new RuntimeException('Rust SSH transfer did not produce a valid local file.');
        }

        return [
            'path' => $destination,
            'name' => (string) ($result['name'] ?? basename($destination)),
            'mime' => (string) ($result['mime'] ?? 'application/octet-stream'),
        ];
    }

    private function request(string $action, array $payload, int $timeout): array
    {
        $payload['transport_id'] = (string) Str::uuid();
        $response = Http::acceptJson()
            ->asJson()
            ->withToken((string) config('serverpanel.execution_api_token'))
            ->connectTimeout(10)
            ->timeout($timeout)
            ->post(rtrim((string) config('serverpanel.execution_api_base_url'), '/').'/api/v1/migration/cyberpanel-ssh/'.$action, $payload);

        if (! $response->successful() || ! $response->json('success')) {
            $detail = $response->json('message')
                ?: trim($response->body())
                ?: 'Rust SSH migration service failed with HTTP '.$response->status().'.';
            throw new RuntimeException(Str::limit((string) $detail, 1000, '…'));
        }

        return (array) $response->json('data', []);
    }
}
