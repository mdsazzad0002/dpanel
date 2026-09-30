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
     * @return array{output:string,error_output:string,exit_code:int|null,reboot_required:bool,reboot_packages:list<string>}
     */
    public function executeOnServer(Server $server, string $command): array
    {
        $ssh = $this->connect($server);

        return $this->runCommand($ssh, $command);
    }

    /**
     * @param  callable(string):void  $onOutputLine
     * @return array{output:string,error_output:string,exit_code:int|null,reboot_required:bool,reboot_packages:list<string>}
     */
    public function executeOnServerStreaming(Server $server, string $command, callable $onOutputLine): array
    {
        $ssh = $this->connect($server);

        return $this->runCommandStreaming($ssh, $command, $onOutputLine);
    }

    /**
     * @return array{output:string,error_output:string,exit_code:int|null,reboot_required:bool,reboot_packages:list<string>}
     */
    public function runCommand(SSH2 $ssh, string $command): array
    {
        return $this->runCommandStreaming($ssh, $command, static function (): void {
        });
    }

    /**
     * @param  callable(string):void  $onOutputLine
     * @return array{output:string,error_output:string,exit_code:int|null,reboot_required:bool,reboot_packages:list<string>}
     */
    public function runCommandStreaming(SSH2 $ssh, string $command, callable $onOutputLine): array
    {
        $collectedOutput = '';
        $lineBuffer = '';
        $finished = false;

        // Stop reading as soon as the exit marker arrives. Upgrades that restart
        // daemons (freshclam, needrestart, ...) leave children holding the SSH
        // channel open, so waiting for EOF would hang until the timeout.
        $ssh->exec($this->wrapCommand($command), function (string $chunk) use (&$collectedOutput, &$lineBuffer, &$finished, $onOutputLine): bool {
            $collectedOutput .= $chunk;
            $lineBuffer .= $chunk;

            while (($lineEnd = strpos($lineBuffer, "\n")) !== false) {
                $line = trim(substr($lineBuffer, 0, $lineEnd));
                $lineBuffer = (string) substr($lineBuffer, $lineEnd + 1);

                if ($line !== '' && ! $this->isControlLine($line)) {
                    $onOutputLine($line);
                }
            }

            $finished = preg_match('/__SERVERPANEL_EXIT__:\d+\r?\n/', $collectedOutput) === 1;

            return $finished;
        });

        if (! $finished && trim($lineBuffer) !== '' && ! $this->isControlLine(trim($lineBuffer))) {
            $onOutputLine(trim($lineBuffer));
        }

        $stderr = (string) $ssh->getStdError();
        $exitCode = null;

        if (preg_match('/__SERVERPANEL_EXIT__:(\d+)/', $collectedOutput, $matches) === 1) {
            $exitCode = (int) $matches[1];
        } elseif (is_int($ssh->getExitStatus())) {
            $exitCode = $ssh->getExitStatus();
        } elseif ($ssh->isTimeout()) {
            $stderr = trim($stderr.PHP_EOL.sprintf(
                'Command did not finish within %d seconds and was stopped waiting. It may still be running on the server.',
                (int) config('serverpanel.command_timeout', 300),
            ));
        } else {
            $stderr = trim($stderr.PHP_EOL.'SSH connection closed before the command reported an exit status.');
        }

        $rebootPackages = [];
        $rebootRequired = preg_match('/__SERVERPANEL_REBOOT__:([^\r\n]*)/', $collectedOutput, $rebootMatches) === 1;
        if ($rebootRequired) {
            $rebootPackages = array_values(array_unique(array_filter(preg_split('/\s+/', trim($rebootMatches[1])) ?: [])));
        }

        $output = (string) preg_replace('/^.*__SERVERPANEL_(?:EXIT|REBOOT)__:.*$\R?/m', '', $collectedOutput);

        return [
            'output' => $this->truncateOutput($this->sanitizeControlMarkers($output)),
            'error_output' => $this->truncateOutput($this->sanitizeControlMarkers($stderr)),
            'exit_code' => $exitCode,
            'reboot_required' => $rebootRequired,
            'reboot_packages' => $rebootPackages,
        ];
    }

    private function wrapCommand(string $command): string
    {
        // Package tools must never prompt: there is no terminal to answer them.
        // needrestart only lists services so it cannot restart sshd under us.
        $script = implode("\n", [
            'export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l APT_LISTCHANGES_FRONTEND=none',
            '(',
            $command,
            ') </dev/null',
            '__serverpanel_status=$?',
            'if [ -f /var/run/reboot-required ]; then',
            '  printf "\n__SERVERPANEL_REBOOT__:%s\n" "$(tr \'\n\' \' \' < /var/run/reboot-required.pkgs 2>/dev/null)"',
            'fi',
            'printf "\n__SERVERPANEL_EXIT__:%s\n" "$__serverpanel_status"',
        ]);

        return 'bash -lc '.escapeshellarg($script);
    }

    private function isControlLine(string $line): bool
    {
        return str_contains($line, '__SERVERPANEL_EXIT__:') || str_contains($line, '__SERVERPANEL_REBOOT__:');
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
