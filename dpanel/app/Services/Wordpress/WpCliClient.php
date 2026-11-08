<?php

namespace App\Services\Wordpress;

use App\Models\Website;
use App\Services\Website\WordpressInstallService;
use Illuminate\Support\Facades\Http;

/**
 * drust's `POST /api/v1/wordpress` (backlog W1, W2, W4): a fixed set of
 * wp-cli commands, run as the website's own system user.
 */
class WpCliClient
{
    public function __construct(private readonly WordpressInstallService $install) {}

    /**
     * @param  array<string, mixed>  $extra  `names`, `fix`, `from`, `to`
     * @return array<string, array{ok: bool, output: string, error: string}>
     */
    public function run(Website $website, string $action, array $extra = []): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust execution API is not configured.');
        }

        $response = Http::acceptJson()->asJson()
            ->timeout(900)
            ->withToken(trim((string) config('serverpanel.execution_api_token', '')))
            ->post(rtrim($baseUrl, '/').'/api/v1/wordpress', [
                'site_owner' => (string) $website->site_owner,
                'root' => $this->root($website),
                'php_version' => (string) $website->php_version,
                'action' => $action,
                ...$extra,
            ]);

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! (bool) ($json['success'] ?? false)) {
            $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
            throw new \RuntimeException($message !== '' ? $message : 'wp-cli request failed: '.$response->status());
        }

        $results = [];
        foreach ((array) ($json['data'] ?? []) as $key => $result) {
            $results[(string) $key] = [
                'ok' => (bool) ($result['ok'] ?? false),
                'output' => (string) ($result['output'] ?? ''),
                'error' => (string) ($result['error'] ?? ''),
            ];
        }

        return $results;
    }

    public function root(Website $website): string
    {
        return $this->install->resolveInstallationRoot($website->only(['root_path', 'start_directory']));
    }
}
