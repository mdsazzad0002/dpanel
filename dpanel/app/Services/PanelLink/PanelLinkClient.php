<?php

namespace App\Services\PanelLink;

use App\Models\PanelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * This panel's side of a PanelConnection: calls the other panel's
 * /api/v1/migration endpoints (Api\V1\MigrationController there) with the
 * token made on that panel. Connection drops and 5xx are retried with
 * backoff; a 4xx is the other panel refusing, so it is not.
 */
class PanelLinkClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $token) {}

    public static function for(PanelConnection $connection): self
    {
        return new self($connection->base_url, (string) $connection->token);
    }

    /** "panel.example.com" or "https://panel.example.com:2083/" → "https://panel.example.com:2083" */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('Enter the other panel\'s address, such as https://panel.example.com');
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function handshake(): array
    {
        return $this->json('get', 'handshake');
    }

    public function inventory(): array
    {
        return $this->json('get', 'inventory', [], 120);
    }

    /** @return array{git: bool, files: list<array{path: string, size: int, sha256: string}>} */
    public function manifest(string $domain): array
    {
        // The other panel hashes every file of the site before answering.
        return $this->json('post', 'manifest', ['domain' => $domain], 900, 2);
    }

    public function startExport(string $kind, ?string $domain = null, array $paths = []): array
    {
        return $this->json('post', 'exports', array_filter(['kind' => $kind, 'domain' => $domain, 'paths' => $paths ?: null], fn ($value) => $value !== null), 120);
    }

    public function export(string $id): array
    {
        return $this->json('get', 'exports/'.$id, [], 60, 3);
    }

    public function exportChunk(string $id, int $index): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->timeout(300)->get($this->url("exports/{$id}/chunks/{$index}")), 5);
        $bytes = $response->body();
        $expected = (string) $response->header('X-Chunk-Sha256');
        if ($expected !== '' && hash('sha256', $bytes) !== $expected) {
            throw new RuntimeException("Chunk {$index} was corrupted in transit.");
        }

        return $bytes;
    }

    public function deleteExport(string $id): void
    {
        try {
            $this->json('delete', 'exports/'.$id);
        } catch (\Throwable) {
            // The other panel removes it after a day anyway.
        }
    }

    public function startUpload(string $kind, int $size, int $total, string $sha256, array $site, array $options = []): array
    {
        return $this->json('post', 'uploads', ['kind' => $kind, 'size' => $size, 'total' => $total, 'sha256' => $sha256, 'site' => $site, 'options' => $options], 120);
    }

    public function uploadChunk(string $id, int $index, string $bytes): void
    {
        $this->send(fn (PendingRequest $http) => $http->timeout(300)
            ->attach('chunk', $bytes, 'chunk-'.$index.'.part')
            ->post($this->url("uploads/{$id}/chunks/{$index}"), ['sha256' => hash('sha256', $bytes)]), 5);
    }

    public function completeUpload(string $id): array
    {
        return $this->json('post', "uploads/{$id}/complete", [], 1800, 2);
    }

    public function upload(string $id): array
    {
        return $this->json('get', 'uploads/'.$id, [], 60, 3);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/api/v1/migration/'.$path;
    }

    private function json(string $method, string $path, array $data = [], int $timeout = 60, int $retries = 1): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->timeout($timeout)->{$method}($this->url($path), $data), $retries);

        return (array) $response->json('data', []);
    }

    private function send(callable $call, int $retries): Response
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $call(Http::acceptJson()->withToken($this->token)->connectTimeout(20));
                if ($response->successful()) {
                    return $response;
                }
                $message = (string) ($response->json('message') ?: 'The other panel answered HTTP '.$response->status().'.');
                if ($response->status() === 404 && $response->json('message') === null) {
                    $message = 'The other panel has no live migration API — update it to this dPanel version first.';
                }
                if ($response->clientError() || $attempt >= $retries) {
                    throw new PanelLinkException($message, $response->status());
                }
            } catch (PanelLinkException $e) {
                throw $e;
            } catch (ConnectionException $e) {
                if ($attempt >= $retries) {
                    throw new PanelLinkException('Could not reach '.$this->baseUrl.': '.$e->getMessage());
                }
            }
            sleep(min(30, 2 ** $attempt));
        }
    }
}
