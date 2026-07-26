<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ScriptExecutionGateway
{
    /**
     * Execute a bootstrap/runtime script through the execution API.
     *
     * @param array<int, string> $arguments
     * @param array<string, string|int|float|bool|null> $environment
     * @return array{success: bool, output: string, exit_code: int, ran: bool, api_url: string, script_path: string}
     */
    public function execute(string $scriptPath, array $arguments = [], array $environment = [], bool $asRoot = false): array
    {
        $apiUrl = trim((string) config('serverpanel.execution_api_url', ''));
        if ($apiUrl === '') {
            return [
                'success' => false,
                'output' => 'Execution API is not configured. Set SERVERPANEL_EXECUTION_API_URL to delegate .sh execution.',
                'exit_code' => 1,
                'ran' => false,
                'api_url' => '',
                'script_path' => $scriptPath,
            ];
        }

        $scriptName = $this->scriptNameFromPath($scriptPath);
        $payload = [
            'script' => $scriptName,
            'args' => array_values(array_map(static fn ($value) => (string) $value, $arguments)),
            'environment' => $this->normalizeEnvironment($environment),
            'run_as_root' => $asRoot,
        ];

        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('serverpanel.execution_api_timeout', 60));

        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $response = $request->post($apiUrl, $payload);
            if (! $response->ok()) {
                return [
                    'success' => false,
                    'output' => trim((string) $response->body()) ?: 'Execution API request failed.',
                    'exit_code' => $response->status(),
                    'ran' => true,
                    'api_url' => $apiUrl,
                    'script_path' => $scriptName,
                ];
            }

            $json = $response->json();
            if (! is_array($json)) {
                return [
                    'success' => false,
                    'output' => 'Execution API returned an invalid response.',
                    'exit_code' => 1,
                    'ran' => true,
                    'api_url' => $apiUrl,
                    'script_path' => $scriptName,
                ];
            }

            $data = is_array($json['data'] ?? null) ? $json['data'] : [];
            $output = (string) ($data['output'] ?? $json['output'] ?? $json['message'] ?? '');

            return [
                'success' => (bool) ($json['success'] ?? false),
                'output' => $output,
                'exit_code' => (int) ($json['exit_code'] ?? ($json['success'] ?? false ? 0 : 1)),
                'ran' => true,
                'api_url' => $apiUrl,
                'script_path' => $scriptName,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'output' => $e->getMessage(),
                'exit_code' => 1,
                'ran' => true,
                'api_url' => $apiUrl,
                'script_path' => $scriptPath,
            ];
        }
    }

    /**
     * @param array<string, string|int|float|bool|null> $environment
     * @return array<string, string>
     */
    private function normalizeEnvironment(array $environment): array
    {
        $normalized = [];
        foreach ($environment as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }

    private function scriptNameFromPath(string $scriptPath): string
    {
        $normalized = trim(str_replace('\\', '/', $scriptPath));
        if ($normalized === '') {
            return '';
        }

        return basename($normalized);
    }
}
