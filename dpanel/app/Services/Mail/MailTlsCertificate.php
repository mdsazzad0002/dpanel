<?php

namespace App\Services\Mail;

use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;

/**
 * The Let's Encrypt certificates Postfix and Dovecot serve, one per mail IP
 * hostname: the default IP's as the default certificate, the others by SNI.
 * Without them they serve the distro's snakeoil certificate, named after the
 * machine, and mail clients fail STARTTLS with "subjectAltName did not match".
 * The script renews only near expiry, so it is safe to run daily.
 */
class MailTlsCertificate
{
    public function __construct(
        private readonly MailIps $ips,
        private readonly ScriptExecutionGateway $gateway,
    ) {
    }

    /** @return array<int, array{host: string, names: array<int, string>, issuer: string, expires: string, covers_host: bool}> */
    public function status(): array
    {
        $hosts = $this->ips->hostnames();
        if ($hosts === []) {
            return [];
        }
        $result = $this->run(['--status', ...$hosts]);
        if (! $result['success']) {
            throw new \RuntimeException(trim($result['output']) ?: 'the status script failed.');
        }

        return array_map(function (string $line) {
            [$host, $names, $issuer, $expires] = array_pad(explode('|', $line, 4), 4, '');
            $names = array_values(array_filter(explode(',', strtolower($names))));

            return ['host' => $host, 'names' => $names, 'issuer' => $issuer, 'expires' => $expires, 'covers_host' => in_array($host, $names, true)];
        }, $this->values($result['output'], 'MAIL_TLS_HOST'));
    }

    /**
     * Issues (or renews near expiry) a certificate for every mail hostname and
     * points Postfix and Dovecot at them. One failing host does not block the rest.
     *
     * @return array{ok: bool, changed: bool, message: string}
     */
    public function ensure(): array
    {
        $hosts = $this->ips->hostnames();
        if ($hosts === []) {
            return ['ok' => false, 'changed' => false, 'message' => 'Set a mail hostname that resolves to this server first.'];
        }

        $result = $this->run($hosts);
        if (! $result['success']) {
            return ['ok' => false, 'changed' => false, 'message' => 'Mail SSL failed: '.(trim($result['output']) ?: 'unknown error.')];
        }
        $ready = array_map(fn ($line) => str_replace('|', ' (expires ', $line).')', $this->values($result['output'], 'MAIL_TLS_READY'));
        $failed = array_map(fn ($line) => str_replace('|', ': ', $line), $this->values($result['output'], 'MAIL_TLS_FAILED'));
        $changed = ($this->values($result['output'], 'MAIL_TLS_CHANGED')[0] ?? '0') === '1';

        $message = $ready !== [] ? 'Mail SSL valid for '.implode(', ', $ready).'.' : '';
        if ($failed !== []) {
            $message = trim($message."\nFailed: ".implode("\n", $failed));
        }

        return ['ok' => $failed === [], 'changed' => $changed, 'message' => $message];
    }

    /** @return array{success: bool, output: string} */
    private function run(array $arguments): array
    {
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/ensure-mail-tls.sh';

        return $this->gateway->execute($script, $arguments, [], true);
    }

    /** @return array<int, string> */
    private function values(string $output, string $key): array
    {
        preg_match_all('/^'.$key.'=(.*)$/m', $output, $matches);

        return array_map('trim', $matches[1]);
    }
}
