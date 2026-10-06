<?php

namespace App\Services\Mail;

use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;

/**
 * The Let's Encrypt certificate Postfix and Dovecot serve for the mail
 * hostname. Without one they serve the distro's snakeoil certificate, named
 * after the machine, and mail clients fail STARTTLS with "subjectAltName did
 * not match". The script renews only near expiry, so it is safe to run daily.
 */
class MailTlsCertificate
{
    public function __construct(
        private readonly MailHostnameDetector $hostname,
        private readonly ScriptExecutionGateway $gateway,
    ) {
    }

    /** @return array{cert: string, names: array<int, string>, issuer: string, expires: string, host: string, covers_host: bool} */
    public function status(): array
    {
        $result = $this->run(['--status']);
        if (! $result['success']) {
            throw new \RuntimeException(trim($result['output']) ?: 'the status script failed.');
        }
        $values = $this->parse($result['output']);
        $names = array_values(array_filter(explode(',', strtolower($values['MAIL_TLS_NAMES'] ?? ''))));
        $host = $this->hostname->current();

        return [
            'cert' => $values['MAIL_TLS_CERT'] ?? '',
            'names' => $names,
            'issuer' => $values['MAIL_TLS_ISSUER'] ?? '',
            'expires' => $values['MAIL_TLS_EXPIRES'] ?? '',
            'host' => $host,
            'covers_host' => $host !== '' && in_array($host, $names, true),
        ];
    }

    /**
     * Issues (or renews near expiry) the certificate for the host, the current
     * mail hostname by default, and points Postfix and Dovecot at it.
     *
     * @return array{ok: bool, changed: bool, host: string, message: string}
     */
    public function ensure(string $host = ''): array
    {
        $host = strtolower(trim($host)) ?: $this->hostname->current();
        if (! MailDomainProvisioner::isValidFqdn($host)) {
            return ['ok' => false, 'changed' => false, 'host' => $host, 'message' => 'Set a mail hostname that resolves to this server first.'];
        }

        $result = $this->run([$host]);
        if (! $result['success']) {
            return ['ok' => false, 'changed' => false, 'host' => $host, 'message' => 'Mail SSL failed: '.(trim($result['output']) ?: 'unknown error.')];
        }
        $values = $this->parse($result['output']);
        $changed = ($values['MAIL_TLS_CHANGED'] ?? '0') === '1';
        $expires = $values['MAIL_TLS_EXPIRES'] ?? '';

        return [
            'ok' => true,
            'changed' => $changed,
            'host' => $host,
            'message' => ($changed ? "Mail SSL installed for {$host}." : "Mail SSL for {$host} is already valid.").($expires !== '' ? " Expires {$expires}." : ''),
        ];
    }

    /** @return array{success: bool, output: string} */
    private function run(array $arguments): array
    {
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/ensure-mail-tls.sh';

        return $this->gateway->execute($script, $arguments, [], true);
    }

    /** @return array<string, string> */
    private function parse(string $output): array
    {
        preg_match_all('/^(MAIL_TLS_[A-Z_]+)=(.*)$/m', $output, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn ($match) => [$match[1] => trim($match[2])])->all();
    }
}
