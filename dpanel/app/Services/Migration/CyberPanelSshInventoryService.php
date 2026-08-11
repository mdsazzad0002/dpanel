<?php

namespace App\Services\Migration;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

class CyberPanelSshInventoryService implements RemotePanelInventoryProvider
{
    public function discover(array $credentials): array
    {
        $ssh = new SSH2((string) $credentials['host'], (int) $credentials['port'], 20);
        $ssh->setTimeout(120);
        $authenticated = $credentials['auth_type'] === 'key'
            ? $ssh->login((string) $credentials['username'], PublicKeyLoader::loadPrivateKey(
                (string) $credentials['private_key'],
                ($credentials['key_passphrase'] ?? '') !== '' ? (string) $credentials['key_passphrase'] : false,
            ))
            : $ssh->login((string) $credentials['username'], (string) $credentials['password']);

        if (! $authenticated) {
            throw new RuntimeException('SSH authentication failed.');
        }

        $version = $this->run($ssh, "cyberpanel --version 2>/dev/null || test -d /usr/local/CyberCP && printf 'CyberPanel'");
        if (stripos($version, 'cyberpanel') === false && ! preg_match('/\d+\.\d+/', $version)) {
            throw new RuntimeException('CyberPanel was not detected on the remote server.');
        }

        $websitePayload = $this->decodeJson($this->run($ssh, 'cyberpanel listWebsitesJson 2>/dev/null'));
        $websiteRows = $this->rows($websitePayload, ['data', 'websites']);
        $websites = [];

        foreach ($websiteRows as $row) {
            $domain = strtolower(trim((string) ($row['domain'] ?? $row['domainName'] ?? $row['website'] ?? '')));
            if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                continue;
            }

            $databasePayload = $this->decodeJson($this->run(
                $ssh,
                'cyberpanel listDatabasesJson --domainName '.escapeshellarg($domain).' 2>/dev/null',
                true,
            ));
            $databaseRows = $this->rows($databasePayload, ['data', 'databases']);
            $databases = collect($databaseRows)->map(function (array $database): array {
                return [
                    'name' => (string) ($database['dbName'] ?? $database['databaseName'] ?? $database['name'] ?? ''),
                    'user' => (string) ($database['dbUser'] ?? $database['databaseUser'] ?? $database['user'] ?? ''),
                ];
            })->filter(fn (array $database): bool => $database['name'] !== '')->values()->all();

            $websites[] = [
                'domain' => $domain,
                'owner' => (string) ($row['adminEmail'] ?? $row['owner'] ?? $row['externalApp'] ?? ''),
                'php_version' => (string) ($row['phpVersion'] ?? $row['php'] ?? ''),
                'path' => (string) ($row['path'] ?? '/home/'.$domain.'/public_html'),
                'databases' => $databases,
            ];
        }

        return [
            'panel' => 'CyberPanel',
            'version' => trim($version),
            'hostname' => trim($this->run($ssh, 'hostname -f 2>/dev/null || hostname')),
            'websites' => $websites,
        ];
    }

    private function run(SSH2 $ssh, string $command, bool $allowFailure = false): string
    {
        $marker = '__DPANEL_EXIT__';
        $output = (string) $ssh->exec('bash -lc '.escapeshellarg($command."\nprintf '\\n{$marker}:%s\\n' \"\$?\""));
        $error = trim((string) $ssh->getStdError());
        $exitCode = preg_match('/'.$marker.':(\d+)/', $output, $matches) === 1 ? (int) $matches[1] : 1;
        $output = trim((string) preg_replace('/(?:\r?\n)?'.$marker.':\d+\s*/', '', $output));
        if ($exitCode !== 0 && ! $allowFailure) {
            throw new RuntimeException($error !== '' ? $error : 'The remote CyberPanel command failed.');
        }

        return $exitCode === 0 ? $output : '';
    }

    private function decodeJson(string $output): array
    {
        if ($output === '') {
            return [];
        }
        $start = min(array_filter([strpos($output, '{'), strpos($output, '[')], fn ($position) => $position !== false) ?: [0]);
        $decoded = json_decode(substr($output, $start), true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int, string> $keys */
    private function rows(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value)) {
                $value = json_decode($value, true);
            }
            if (is_array($value)) {
                return array_is_list($value) ? $value : array_values($value);
            }
        }

        return array_is_list($payload) ? $payload : [];
    }
}
