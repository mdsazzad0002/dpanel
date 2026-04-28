<?php

namespace App\Services\ServerPanel;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\NoSupportedAlgorithmsException;
use phpseclib3\Net\SSH2;
use RuntimeException;

class SshClientService
{
    /**
     * @return array{status:string,output:string,error_output:string,latency_ms:int|null,meta:array<string,string|null>}
     */
    public function testConnection(Server $server): array
    {
        $started = microtime(true);

        try {
            $ssh = $this->connect($server);
            $chunks = [];

            foreach (['whoami', 'hostname', 'uname -a'] as $checkCommand) {
                $result = $this->runCommand($ssh, $checkCommand);
                $chunks[] = '$ '.$checkCommand;
                $chunks[] = trim($result['output']) !== '' ? trim($result['output']) : '(no output)';
            }

            return [
                'status' => 'success',
                'output' => implode(PHP_EOL.PHP_EOL, $chunks),
                'error_output' => '',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'meta' => [],
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'failed',
                'output' => '',
                'error_output' => $exception->getMessage(),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'meta' => [],
            ];
        }
    }

    public function connect(Server $server): SSH2
    {
        if (! config('serverpanel.allow_root_setup_mode', true) && strtolower($server->username) === 'root') {
            throw new RuntimeException('Root login is disabled by configuration.');
        }

        if (strtolower($server->username) === 'root' && $server->mode !== 'setup') {
            throw new RuntimeException('Root login is only allowed in setup mode.');
        }

        $ssh = new SSH2($server->host, (int) $server->port, (int) config('serverpanel.ssh_timeout', 20));
        $ssh->setTimeout((int) config('serverpanel.command_timeout', 300));
        $ssh->enableQuietMode();

        $authenticated = match ($server->auth_type) {
            'password' => $this->loginWithPassword($ssh, $server),
            'key' => $this->loginWithPrivateKey($ssh, $server),
            default => false,
        };

        if (! $authenticated) {
            throw new RuntimeException('SSH authentication failed.');
        }

        return $ssh;
    }

    /**
     * @return array{output:string,error_output:string,exit_code:int|null}
     */
    public function executeOnServer(Server $server, string $command): array
    {
        $ssh = $this->connect($server);

        return $this->runCommand($ssh, $command);
    }

    /**
     * @return array{output:string,error_output:string,exit_code:int|null}
     */
    public function runCommand(SSH2 $ssh, string $command): array
    {
        $wrapped = "bash -lc " . escapeshellarg($command."\n".'printf "\\n__SERVERPANEL_EXIT__:%s\\n" "$?"');

        $output = (string) $ssh->exec($wrapped);
        $stderr = (string) $ssh->getStdError();

        $exitCode = null;
        if (preg_match('/__SERVERPANEL_EXIT__:(\d+)\s*$/m', $output, $matches) === 1) {
            $exitCode = (int) $matches[1];
            $output = trim((string) preg_replace('/\n?__SERVERPANEL_EXIT__:\d+\s*$/m', '', $output));
        }

        return [
            'output' => $this->truncateOutput($output),
            'error_output' => $this->truncateOutput($stderr),
            'exit_code' => $exitCode,
        ];
    }

    private function loginWithPassword(SSH2 $ssh, Server $server): bool
    {
        if (! is_string($server->encrypted_password) || $server->encrypted_password === '') {
            throw new RuntimeException('Password is required for password authentication.');
        }

        return $ssh->login($server->username, $server->encrypted_password);
    }

    private function loginWithPrivateKey(SSH2 $ssh, Server $server): bool
    {
        if (! is_string($server->encrypted_private_key) || $server->encrypted_private_key === '') {
            throw new RuntimeException('Private key is required for key authentication.');
        }

        try {
            $key = PublicKeyLoader::loadPrivateKey(
                $server->encrypted_private_key,
                $server->encrypted_private_key_passphrase ?: false,
            );
        } catch (NoSupportedAlgorithmsException $exception) {
            throw new RuntimeException('Private key format is unsupported.', previous: $exception);
        }

        return $ssh->login($server->username, $key);
    }

    private function truncateOutput(string $output): string
    {
        $maxLength = (int) config('serverpanel.max_output_length', 120000);

        if (mb_strlen($output) <= $maxLength) {
            return $output;
        }

        return mb_substr($output, 0, $maxLength).PHP_EOL.'[output truncated]';
    }
}
