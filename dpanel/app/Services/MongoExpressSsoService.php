<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Signs the browser into mongo-express. drust returns a one-time URL (valid
 * for a minute) that the edge gateway turns into a mongo-express session.
 * See drust/src/mongo_express_sso.rs.
 */
class MongoExpressSsoService
{
    public function url(): string
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust API is not configured.');
        }

        $request = Http::acceptJson()->asJson()->timeout(20);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post(rtrim($baseUrl, '/').'/api/v1/mongodb/express-login');
        } catch (\Throwable $e) {
            throw new \RuntimeException('drust API request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $url = (string) ($json['data']['url'] ?? '');
        if (! $response->successful() || ! ($json['success'] ?? false) || ! str_starts_with($url, '/mongo-express/')) {
            throw new \RuntimeException((string) ($json['message'] ?? 'mongo-express sign-in failed.'));
        }

        return $url;
    }
}
