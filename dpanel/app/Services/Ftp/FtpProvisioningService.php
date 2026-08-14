<?php

namespace App\Services\Ftp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FtpProvisioningService
{
    /** @param array<string, mixed> $payload */
    public function run(array $payload): void
    {
        $baseUrl = rtrim((string) config('serverpanel.execution_api_base_url'), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('FTP provisioning service is not configured.');
        }

        $request = Http::acceptJson()->asJson()->timeout((int) config('serverpanel.execution_api_timeout', 60));
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post($baseUrl.'/api/v1/ftp-account', $payload);
        } catch (\Throwable $exception) {
            throw new RuntimeException('FTP provisioning service is unavailable: '.$exception->getMessage(), previous: $exception);
        }

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! ($json['success'] ?? false)) {
            throw new RuntimeException((string) ($json['message'] ?? 'FTP provisioning failed.'));
        }
    }
}
