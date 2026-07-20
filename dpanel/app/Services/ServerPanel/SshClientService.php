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
     * @param  callable(string):void  $onOutputLine
     * @return array{output:string,error_output:string,exit_code:int|null}
     */
    public function executeOnServerStreaming(Server $server, string $command, callable $onOutputLine): array
    {
        $ssh = $this->connect($server);

        return $this->runCommandStreaming($ssh, $command, $onOutputLine);
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
        if (preg_match('/__SERVERPANEL_EXIT__:(\d+)/', $output, $matches) === 1) {
            $exitCode = (int) $matches[1];
            $output = trim((string) preg_replace('/(?:\r?\n)?__SERVERPANEL_EXIT__:\d+\s*/', '', $output));
        }

        return [
            'output' => $this->truncateOutput($this->sanitizeControlMarkers($output)),
            'error_output' => $this->truncateOutput($this->sanitizeControlMarkers($stderr)),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @param  callable(string):void  $onOutputLine
     * @return array{output:string,error_output:string,exit_code:int|null}
     */
    public function runCommandStreaming(SSH2 $ssh, string $command, callable $onOutputLine): array
    {
        $wrapped = "bash -lc " . escapeshellarg($command."\n".'printf "\\n__SERVERPANEL_EXIT__:%s\\n" "$?"');
        $collectedOutput = '';
        $lineBuffer = '';

        $output = (string) $ssh->exec($wrapped, function (string $chunk) use (&$collectedOutput, &$lineBuffer, $onOutputLine): void {
            $collectedOutput .= $chunk;
            $lineBuffer .= $chunk;

            while (($lineEnd = strpos($lineBuffer, "\n")) !== false) {
                $line = trim(substr($lineBuffer, 0, $lineEnd));
                $lineBuffer = (string) substr($lineBuffer, $lineEnd + 1);

                if ($line !== '' && ! str_contains($line, '__SERVERPANEL_EXIT__:')) {
                    $onOutputLine($line);
                }
            }
        });

        if (trim($lineBuffer) !== '' && ! str_contains(trim($lineBuffer), '__SERVERPANEL_EXIT__:')) {
            $onOutputLine(trim($lineBuffer));
        }

        $stderr = (string) $ssh->getStdError();
        $exitCode = null;

        if (preg_match('/__SERVERPANEL_EXIT__:(\d+)/', $output, $matches) === 1) {
            $exitCode = (int) $matches[1];
            $output = trim((string) preg_replace('/(?:\r?\n)?__SERVERPANEL_EXIT__:\d+\s*/', '', $output));
        }

        return [
            'output' => $this->truncateOutput($this->sanitizeControlMarkers($output !== '' ? $output : $collectedOutput)),
            'error_output' => $this->truncateOutput($this->sanitizeControlMarkers((string) preg_replace('/(?:\r?\n)?__SERVERPANEL_EXIT__:\d+\s*/', '', $stderr))),
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

    private function sanitizeControlMarkers(string $text): string
    {
        $text = (string) preg_replace('/(?:^|\R)\s*n?__SERVERPANEL_EXIT__:\d*\s*(?=\R|$)/m', PHP_EOL, $text);
        $text = (string) preg_replace('/n__SERVERPANEL_EXIT__:\d*/', '', $text);

        return trim($text);
    }
}
