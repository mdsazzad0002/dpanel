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
        $detector = app(MailHostnameDetector::class);
        $current = $detector->current();
        $candidates = $detector->candidates();

        // A name that already resolves here stays; otherwise take the best one that does.
        if (collect($candidates)->contains(fn ($c) => $c['host'] === $current && $c['usable'])) {
            return ['ok' => true, 'message' => ''];
        }
        if ($detector->best() !== '') {
            $result = $detector->applyBest();

            return ['ok' => $result['ok'], 'message' => $result['message']];
        }
        if (self::isValidFqdn($current)) {
            return ['ok' => true, 'message' => ''];
        }

        // Nothing resolves here yet: any real FQDN beats an invalid HELO name.
        // The panel's own domain comes first: the server name is shared by
        // every domain, so it must not belong to a customer who may leave.
        $panel = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
        $target = 'mail.'.(self::isValidFqdn($panel) ? $panel : strtolower(trim($domain)));
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/ensure-mail-hostname.sh';
        $result = $this->gateway->execute($script, [$target], [], true);

        return $result['success']
            ? ['ok' => true, 'message' => "Mail server hostname set to {$target}; point its A record at this server."]
            : ['ok' => false, 'message' => 'Mail server hostname could not be set: '.(trim($result['output']) ?: 'unknown error.')];
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
