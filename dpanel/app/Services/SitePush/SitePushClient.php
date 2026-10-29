<?php

namespace App\Services\SitePush;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Local-side HTTP client for a live panel's /api/site-push/v1 endpoints
 * (SitePushApiController there). Only this side needs to reach the other —
 * which is the point: a local panel has internet but no public address.
 */
class SitePushClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $token) {}

    /** @return array{domain:string,chunk_size:int,max_chunks:int} */
    public function handshake(): array
    {
        return $this->call(fn (PendingRequest $http) => $http->post($this->url('handshake')));
    }

    /** @return array{upload_id:string} */
    public function startUpload(int $size, int $total, string $sha256, string $sourceDomain, bool $hasDatabase): array
    {
        return $this->call(fn (PendingRequest $http) => $http->post($this->url('uploads'), [
            'size' => $size, 'total' => $total, 'sha256' => $sha256,
            'source_domain' => $sourceDomain, 'has_database' => $hasDatabase,
        ]));
    }

    public function uploadChunk(string $uploadId, int $index, string $bytes): void
    {
        $this->call(fn (PendingRequest $http) => $http->timeout(300)
            ->attach('chunk', $bytes, 'chunk-'.$index.'.part')
            ->post($this->url('uploads/'.$uploadId.'/chunks/'.$index), ['sha256' => hash('sha256', $bytes)]), retries: 5);
    }

    public function complete(string $uploadId): array
    {
        return $this->call(fn (PendingRequest $http) => $http->timeout(1800)->post($this->url('uploads/'.$uploadId.'/complete')), retries: 2);
    }

    /** @return array{stage:string,message?:string} */
    public function status(string $uploadId): array
    {
        return $this->call(fn (PendingRequest $http) => $http->get($this->url('uploads/'.$uploadId)), retries: 3);
    }

    private function url(string $path): string
    {
        return $this->baseUrl.'/api/site-push/v1/'.$path;
    }

    /**
     * Retries connection drops/5xx with backoff (a home uplink blips); a 4xx
     * is the live panel refusing (bad/expired token, validation), so it isn't.
     */
    private function call(callable $send, int $retries = 1): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $send(Http::acceptJson()->withToken($this->token)->timeout(60)->connectTimeout(20));
                if ($response->successful() && $response->json('ok') === true) {
                    return (array) $response->json();
                }
                $message = (string) ($response->json('message') ?: 'Live panel answered HTTP '.$response->status().'.');
                if ($response->clientError() || $attempt >= $retries) {
                    throw new SitePushException($message, $response->status());
                }
            } catch (SitePushException $e) {
                throw $e;
            } catch (ConnectionException $e) {
                if ($attempt >= $retries) {
                    throw new SitePushException('Could not reach the live panel at '.$this->baseUrl.': '.$e->getMessage());
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Live panel request failed: '.$e->getMessage(), 0, $e);
            }
            sleep(min(30, 2 ** $attempt));
        }
    }
}
