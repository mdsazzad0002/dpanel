<?php

namespace App\Services\Mail;

use App\Models\MailDomain;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;

/**
 * Prepares everything a domain needs to send mail, at the moment a mailbox is
 * created. Each step checks first and is skipped when already done, so it is
 * safe to run on every mailbox creation.
 */
class MailDomainProvisioner
{
    public function __construct(private readonly ScriptExecutionGateway $gateway)
    {
    }

    /**
     * @return array{ok: bool, messages: array<int, string>}
     */
    public function ensureReady(string $domain): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ok' => true, 'messages' => []];
        }

        $messages = [];
        $ok = true;

        $hostname = $this->ensureServerHostname($domain);
        $ok = $ok && $hostname['ok'];
        if ($hostname['message'] !== '') {
            $messages[] = $hostname['message'];
        }

        $dkim = $this->ensureDkim($domain);
        $ok = $ok && $dkim['ok'];
        if ($dkim['message'] !== '') {
            $messages[] = $dkim['message'];
        }

        return ['ok' => $ok, 'messages' => $messages];
    }

    /**
     * Server-level: Postfix must announce a real FQDN in HELO, otherwise
     * remote servers reject with "550 Invalid HELO name".
     *
     * @return array{ok: bool, message: string}
     */
    public function ensureServerHostname(string $domain): array
    {
        $current = strtolower(trim((string) @shell_exec('postconf -h myhostname 2>/dev/null')));
        if (self::isValidFqdn($current)) {
            return ['ok' => true, 'message' => ''];
        }

        $target = strtolower(trim((string) config('serverpanel.mail.hostname', ''))) ?: 'mail.'.strtolower(trim($domain));
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/ensure-mail-hostname.sh';
        $result = $this->gateway->execute($script, [$target], [], true);

        if (! $result['success'] || ! preg_match('/^MAIL_HOSTNAME=(.+)$/m', $result['output'], $match)) {
            return ['ok' => false, 'message' => 'Mail server hostname could not be set: '.(trim($result['output']) ?: 'unknown error.')];
        }

        return str_contains($result['output'], 'MAIL_HOSTNAME_CHANGED=1')
            ? ['ok' => true, 'message' => 'Mail server hostname set to '.trim($match[1]).'.']
            : ['ok' => true, 'message' => ''];
    }

    /**
     * Domain-level: DKIM key + OpenDKIM signing entry. Reuses an existing key.
     *
     * @return array{ok: bool, message: string}
     */
    public function ensureDkim(string $domain, bool $force = false): array
    {
        $domain = strtolower(trim($domain));
        $existing = MailDomain::query()->where('domain', $domain)->first();
        if (! $force && $existing && $existing->enable_dkim && trim((string) $existing->dkim_public_key) !== '') {
            return ['ok' => true, 'message' => ''];
        }

        $selector = trim((string) config('serverpanel.mail.dkim_selector', 'default')) ?: 'default';
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/generate-dkim.sh';
        $result = $this->gateway->execute($script, [$domain, $selector], [], true);

        if (! $result['success'] || ! preg_match('/^DKIM_PUBLIC_KEY=(.+)$/m', $result['output'], $match)) {
            return ['ok' => false, 'message' => 'DKIM setup failed for '.$domain.': '.(trim($result['output']) ?: 'unknown error.')];
        }

        MailDomain::query()->updateOrCreate(['domain' => $domain], [
            'enable_dkim' => true,
            'dkim_selector' => $selector,
            'dkim_public_key' => trim($match[1]),
            'status' => 'active',
        ]);

        return ['ok' => true, 'message' => "DKIM signing enabled for {$domain}."];
    }

    public static function isValidFqdn(string $name): bool
    {
        $name = strtolower(trim($name));

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name)
            && ! preg_match('/\.(?:localdomain|local|lan|internal)$/', $name);
    }
}
