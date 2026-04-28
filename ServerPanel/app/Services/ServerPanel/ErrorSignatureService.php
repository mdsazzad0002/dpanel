<?php

namespace App\Services\ServerPanel;

class ErrorSignatureService
{
    public function signatureFrom(string $errorOutput, ?string $command = null): string
    {
        $haystack = mb_strtolower(trim($errorOutput.' '.$command));

        $named = [
            'permission_denied' => '/permission denied/',
            'service_failed_to_start' => '/failed to start|start request repeated too quickly/',
            'port_already_in_use' => '/address already in use|port .* already/',
            'command_not_found' => '/command not found|not recognized as an internal or external command/',
            'disk_full' => '/no space left on device|disk full/',
            'mysql_access_denied' => '/mysql.*access denied|mariadb.*access denied/',
            'composer_dependency_error' => '/composer.*dependency|your requirements could not be resolved/',
            'npm_build_error' => '/npm err!|vite build failed|webpack compilation/',
            'php_extension_missing' => '/php.*extension.*(missing|not found)/',
            'laravel_env_missing' => '/\.env.*(missing|not found)/',
            'laravel_key_missing' => '/application key|no application encryption key has been specified/',
            'storage_permission_error' => '/storage\/logs|failed to open stream: permission denied/',
        ];

        foreach ($named as $signature => $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return $signature;
            }
        }

        $normalized = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/', '<date>', $haystack) ?? $haystack;
        $normalized = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27}\b/', '<uuid>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\/[^\s]+/', '<path>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d+\b/', '<num>', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? $normalized;

        return 'sig_'.sha1($normalized);
    }
}
