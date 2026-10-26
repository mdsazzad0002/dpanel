<?php

namespace App\Services\Ftp;

/**
 * What the FTP server is actually configured with, read from vsftpd.conf
 * (written by the dscript ftp module), plus where this server sits on the
 * network. Pages use it to show real values in the setup guide, and the
 * tester uses it to explain a failed data connection.
 */
class FtpServerInfo
{
    private const DEFAULT_PASV_MIN = 30000;

    private const DEFAULT_PASV_MAX = 30100;

    /**
     * @return array{public_ip: string, local_ip: string, behind_nat: bool, pasv_min: int, pasv_max: int, self_signed: bool, config_found: bool}
     */
    public function describe(string $host): array
    {
        $settings = $this->settings();
        $localIp = $this->localIp();
        $publicIp = $settings['pasv_address'] !== '' ? $settings['pasv_address'] : $this->resolve($host);
        if ($publicIp === '' && $this->isPublic($localIp)) {
            $publicIp = $localIp;
        }

        return [
            'public_ip' => $publicIp,
            'local_ip' => $localIp,
            'behind_nat' => $localIp !== '' && ! $this->isPublic($localIp) && $this->isPublic($publicIp),
            'pasv_min' => $settings['pasv_min'],
            'pasv_max' => $settings['pasv_max'],
            'self_signed' => $settings['self_signed'],
            'config_found' => $settings['config_found'],
        ];
    }

    public function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return array{pasv_min: int, pasv_max: int, pasv_address: string, self_signed: bool, config_found: bool}
     */
    private function settings(): array
    {
        $path = (string) config('serverpanel.ftp.config_path', '/etc/vsftpd.conf');
        $lines = is_readable($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            // vsftpd uses the last value of a repeated option.
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $values[$key] = $value;
        }

        $min = (int) ($values['pasv_min_port'] ?? self::DEFAULT_PASV_MIN);
        $max = (int) ($values['pasv_max_port'] ?? self::DEFAULT_PASV_MAX);

        return [
            'pasv_min' => $min > 0 ? $min : self::DEFAULT_PASV_MIN,
            'pasv_max' => $max >= $min && $max > 0 ? $max : self::DEFAULT_PASV_MAX,
            'pasv_address' => $this->isPublic((string) ($values['pasv_address'] ?? '')) ? (string) $values['pasv_address'] : '',
            'self_signed' => str_contains((string) ($values['rsa_cert_file'] ?? ''), 'snakeoil'),
            'config_found' => $lines !== [],
        ];
    }

    /**
     * The address this server uses to reach the internet. A UDP "connect"
     * sends nothing; it only makes the kernel pick the outgoing interface.
     */
    private function localIp(): string
    {
        $socket = @stream_socket_client('udp://1.1.1.1:53', $errno, $errstr, 1);
        if ($socket === false) {
            return '';
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (string) preg_replace('/:\d+$/', '', $name);
    }

    private function resolve(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        $ip = gethostbyname($host);

        return $ip !== $host ? $ip : '';
    }
}
