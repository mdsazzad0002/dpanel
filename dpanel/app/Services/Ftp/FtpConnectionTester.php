<?php

namespace App\Services\Ftp;

/**
 * Checks the FTP server the way a client would: reach the port, read the
 * greeting, upgrade to TLS, and optionally log in and list the directory.
 * Every check returns its steps so the page can show where it stopped.
 */
class FtpConnectionTester
{
    private const TIMEOUT_SECONDS = 8;

    private const REACH_TIMEOUT_SECONDS = 4;

    public function __construct(private readonly FtpServerInfo $serverInfo) {}

    /**
     * @return array{ok: bool, steps: list<array{label: string, ok: bool, detail: string}>, diagnosis: array<string, mixed>|null}
     */
    public function probe(string $host, int $port, bool $tls): array
    {
        $steps = [];
        $socket = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT_SECONDS);
        if ($socket === false) {
            $steps[] = $this->step("Connect to {$host}:{$port}", false, $errstr !== '' ? $errstr : 'Connection refused or timed out. Is the FTP service running?');

            return $this->result($steps);
        }
        stream_set_timeout($socket, self::TIMEOUT_SECONDS);
        $steps[] = $this->step("Connect to {$host}:{$port}", true, 'Port is reachable.');

        $greeting = $this->readReply($socket);
        $steps[] = $this->step('Server greeting', str_starts_with($greeting, '220'), $greeting !== '' ? $greeting : 'No greeting received.');

        if ($tls && str_starts_with($greeting, '220')) {
            fwrite($socket, "AUTH TLS\r\n");
            $reply = $this->readReply($socket);
            $steps[] = $this->step('Explicit TLS (AUTH TLS)', str_starts_with($reply, '234'), $reply !== '' ? $reply : 'No reply to AUTH TLS.');
        }

        @fwrite($socket, "QUIT\r\n");
        fclose($socket);

        $diagnosis = $this->diagnosePassive($host, $port);
        $steps[] = $this->step('Passive data ports', $diagnosis['cause'] === 'reachable', $diagnosis['detail']);

        return $this->result($steps, $diagnosis);
    }

    /**
     * @return array{ok: bool, steps: list<array{label: string, ok: bool, detail: string}>, diagnosis: array<string, mixed>|null}
     */
    public function login(string $host, int $port, bool $tls, string $username, string $password): array
    {
        $steps = [];
        $connection = $tls
            ? @ftp_ssl_connect($host, $port, self::TIMEOUT_SECONDS)
            : @ftp_connect($host, $port, self::TIMEOUT_SECONDS);
        if ($connection === false) {
            $steps[] = $this->step('Connect'.($tls ? ' with TLS' : ''), false, 'Could not open a connection to the FTP server.');

            return $this->result($steps);
        }
        $steps[] = $this->step('Connect'.($tls ? ' with TLS' : ''), true, "{$host}:{$port}");

        try {
            if (! @ftp_login($connection, $username, $password)) {
                $steps[] = $this->step("Log in as {$username}", false, 'Login rejected. Check the username and password.');

                return $this->result($steps);
            }
            $steps[] = $this->step("Log in as {$username}", true, 'Credentials accepted.');

            // Ask for a passive port ourselves first: ftp_pasv() hides the
            // reply, and its address is what explains most listing failures.
            $passive = $this->passiveEndpoint(@ftp_raw($connection, 'PASV') ?: []);
            if ($passive !== null) {
                $private = ! filter_var($passive['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
                $steps[] = $this->step(
                    'Passive address advertised',
                    ! $private,
                    $private
                        ? "{$passive['ip']}:{$passive['port']} is a private address that clients outside this network cannot reach. Set pasv_address in vsftpd.conf to the public IP."
                        : "{$passive['ip']}:{$passive['port']}",
                );
            }

            ftp_pasv($connection, true);
            $directory = (string) @ftp_pwd($connection);
            $entries = @ftp_nlist($connection, '.');
            if ($entries !== false) {
                $steps[] = $this->step(
                    'List directory (passive mode)',
                    true,
                    sprintf('%s: %d %s.', $directory !== '' ? $directory : '/', count($entries), count($entries) === 1 ? 'entry' : 'entries'),
                );
            } else {
                $steps[] = $this->step('List directory (passive mode)', false, 'Logged in, but the data connection for the listing failed.');
                $diagnosis = $this->diagnosePassive($host, $port);
                if ($diagnosis['cause'] === 'reachable') {
                    $diagnosis['cause'] = 'data_channel';
                    $diagnosis['detail'] = 'Passive ports are reachable, so the network is fine. The data connection itself failed: check /var/log/vsftpd.log for TLS or "Bad IP connecting" errors.';
                }
                $steps[] = $this->step('Find the cause', false, $diagnosis['detail']);
            }
        } finally {
            @ftp_close($connection);
        }

        return $this->result($steps, $diagnosis ?? null);
    }

    /**
     * Works out why clients cannot open a passive data connection, without
     * needing an FTP login: listen on a free port in the passive range, then
     * try to reach it through the public IP, the way an outside client would.
     *
     * Causes: reachable, nat_forward (router does not forward the range),
     * firewall (server or provider firewall drops it), no_hairpin (this
     * server cannot reach its own public IP, so it cannot test from inside),
     * no_public_ip, no_free_port.
     *
     * @return array{cause: string, detail: string, port: int, public_ip: string, local_ip: string, behind_nat: bool, pasv_min: int, pasv_max: int}
     */
    public function diagnosePassive(string $host, int $controlPort): array
    {
        $info = $this->serverInfo->describe($host);
        $range = "{$info['pasv_min']}-{$info['pasv_max']}";
        $result = fn (string $cause, string $detail, int $port = 0): array => [
            'cause' => $cause, 'detail' => $detail, 'port' => $port,
            'public_ip' => $info['public_ip'], 'local_ip' => $info['local_ip'], 'behind_nat' => $info['behind_nat'],
            'pasv_min' => $info['pasv_min'], 'pasv_max' => $info['pasv_max'],
        ];

        if ($info['public_ip'] === '') {
            return $result('no_public_ip', 'No public IP is known for this server, so clients are sent an address they cannot reach. Set DPANEL_FTP_PASV_ADDRESS and run: dpanel module ftp update');
        }

        [$listener, $port] = $this->listenOnFreePort($info['pasv_min'], $info['pasv_max']);
        if ($listener === null) {
            return $result('no_free_port', "Could not open a test port in {$range}; all tried ports were busy. Try again in a moment.");
        }

        try {
            if ($this->canReach($info['public_ip'], $port)) {
                return $result('reachable', "Port {$port} is reachable through {$info['public_ip']}.", $port);
            }
            if (! $this->canReach($info['public_ip'], $controlPort)) {
                return $result('no_hairpin', "This server cannot reach its own public IP {$info['public_ip']}, so it cannot test from inside. Test from a computer outside this network.", $port);
            }

            return $info['behind_nat']
                ? $result('nat_forward', "Port {$port} is open on this server but the router does not forward it. Forward TCP {$range} from {$info['public_ip']} to {$info['local_ip']} on the router.", $port)
                : $result('firewall', "Port {$port} is open on this server but blocked on the way in. Allow TCP {$range} in the provider's firewall (security group) and in ufw.", $port);
        } finally {
            fclose($listener);
        }
    }

    /**
     * @return array{0: resource|null, 1: int}
     */
    private function listenOnFreePort(int $min, int $max): array
    {
        $ports = range($min, $max);
        shuffle($ports);
        foreach (array_slice($ports, 0, 20) as $port) {
            $listener = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);
            if ($listener !== false) {
                return [$listener, $port];
            }
        }

        return [null, 0];
    }

    private function canReach(string $ip, int $port): bool
    {
        $socket = @fsockopen($ip, $port, $errno, $errstr, self::REACH_TIMEOUT_SECONDS);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /**
     * Parses "227 Entering Passive Mode (h1,h2,h3,h4,p1,p2)".
     *
     * @param  list<string>  $reply
     * @return array{ip: string, port: int}|null
     */
    private function passiveEndpoint(array $reply): ?array
    {
        if (preg_match('/^227 .*?(\d+),(\d+),(\d+),(\d+),(\d+),(\d+)/', implode("\n", $reply), $m) !== 1) {
            return null;
        }

        return ['ip' => "{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}", 'port' => ((int) $m[5] * 256) + (int) $m[6]];
    }

    /** @param resource $socket */
    private function readReply($socket): string
    {
        // Multi-line replies ("220-...") end with a line that has a space after the code.
        $reply = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $reply .= $line;
            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }

        return trim($reply);
    }

    /** @return array{label: string, ok: bool, detail: string} */
    private function step(string $label, bool $ok, string $detail): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * @param  list<array{label: string, ok: bool, detail: string}>  $steps
     * @param  array<string, mixed>|null  $diagnosis
     * @return array{ok: bool, steps: list<array{label: string, ok: bool, detail: string}>, diagnosis: array<string, mixed>|null}
     */
    private function result(array $steps, ?array $diagnosis = null): array
    {
        return [
            'ok' => $steps !== [] && collect($steps)->every(fn (array $step): bool => $step['ok']),
            'steps' => $steps,
            'diagnosis' => $diagnosis,
        ];
    }
}
