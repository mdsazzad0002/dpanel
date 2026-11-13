<?php

namespace App\Services\Website;

use App\Models\WebsiteTrashBackup;
use Illuminate\Support\Facades\Http;

/**
 * Removes a website trash backup: the ZIP through drust, then the panel row.
 */
class TrashBackupDeleter
{
    public function delete(WebsiteTrashBackup $backup): void
    {
        $path = (string) $backup->file_path;
        // A row whose archive is already gone only needs the record removed.
        if ($path !== '' && is_file($path)) {
            $this->deleteArchive($path);
        }

        $backup->delete();
    }

    public function deleteArchive(string $path): void
    {
        $baseUrl = trim((string) config('dpanel.execution_api_base_url', ''));
        $token = trim((string) config('dpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') {
            throw new \RuntimeException('Rust execution API is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('dpanel.execution_api_timeout', 60))
            ->withToken($token)
            ->post(rtrim($baseUrl, '/').'/api/v1/website/archive/delete', [
                'zip_path' => $path,
            ]);
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'Rust archive cleanup failed.'));
        }
    }
}
