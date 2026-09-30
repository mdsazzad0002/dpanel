<?php

namespace App\Services\Mail;

/**
 * The DNS records a domain needs to receive and send mail through this server.
 *
 * Every domain uses the same mail host (the server's own mail hostname, or the
 * panel domain), so no per-domain "mail." A record is needed. SPF names the
 * server IP directly, which keeps it passing even when the MX host lives in
 * another zone.
 */
class MailDnsRecords
{
    /**
     * The one hostname all domains point MX at: the name Postfix announces in
     * HELO (set by MailHostnameDetector), then the configured preference.
     */
    public function mailHost(): string
    {
        $postfix = strtolower(trim((string) @shell_exec('postconf -h myhostname 2>/dev/null')));
        if (MailDomainProvisioner::isValidFqdn($postfix)) {
            return $postfix;
        }

        $configured = strtolower(trim((string) config('serverpanel.mail.hostname', '')));
        if (MailDomainProvisioner::isValidFqdn($configured)) {
            return $configured;
        }

        $panel = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));

        return MailDomainProvisioner::isValidFqdn($panel) ? $panel : '';
    }

    /** The public IP mail leaves from, or '' when it cannot be determined. */
    public function serverIp(): string
    {
        $configured = trim((string) config('serverpanel.mail.server_ip', ''));
        if (filter_var($configured, FILTER_VALIDATE_IP)) {
            return $configured;
        }

        // The machine's own outbound address first: a mail host name may still
        // point at another server, and trusting DNS would put that IP in SPF.
        $candidates = [trim((string) @shell_exec("ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if (\$i==\"src\") {print \$(i+1); exit}}'"))];
        $host = $this->mailHost();
        if ($host !== '') {
            $candidates[] = (string) @gethostbyname($host);
        }

        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return '';
    }

    /**
     * Where the mail host resolves, when that is not this server; '' when it
     * matches or cannot be checked. MX pointing at such a host sends mail to
     * the wrong machine, and a HELO name that does not match hurts delivery.
     */
    public function mailHostMismatch(): string
    {
        $host = $this->mailHost();
        $ip = $this->serverIp();
        if ($host === '' || $ip === '') {
            return '';
        }
        $resolved = @gethostbynamel($host) ?: [];

        return $resolved === [] || in_array($ip, $resolved, true) ? '' : implode(', ', $resolved);
    }

    public function spf(): string
    {
        $ip = $this->serverIp();
        if ($ip !== '') {
            return 'v=spf1 '.(str_contains($ip, ':') ? 'ip6:' : 'ip4:').$ip.' mx ~all';
        }
        $host = $this->mailHost();

        return 'v=spf1 mx'.($host !== '' ? ' a:'.$host : '').' ~all';
    }

    /**
     * Rows for the Mail DNS Guide and the zone export. Names are relative to
     * the domain ('@', 'mail', ...).
     *
     * @return array<int, array{type: string, name: string, value: string, priority: ?int, purpose: string}>
     */
    public function records(string $domain, string $selector, string $dkimPublicKey): array
    {
        $domain = strtolower(trim($domain));
        $host = $this->mailHost() ?: 'mail.'.$domain;
        $records = [];

        // Only a mail host inside this domain needs an address record here.
        if ($host === $domain || str_ends_with($host, '.'.$domain)) {
            $name = $host === $domain ? '@' : substr($host, 0, -strlen('.'.$domain));
            $records[] = ['type' => 'A', 'name' => $name, 'value' => $this->serverIp(), 'priority' => null, 'purpose' => 'Mail server hostname'];
        }

        return [
            ...$records,
            ['type' => 'MX', 'name' => '@', 'value' => $host, 'priority' => 10, 'purpose' => 'Receive email for '.$domain],
            ['type' => 'TXT', 'name' => '@', 'value' => $this->spf(), 'priority' => null, 'purpose' => 'Authorize this server to send email (SPF)'],
            ['type' => 'TXT', 'name' => $selector.'._domainkey', 'value' => $dkimPublicKey !== '' ? 'v=DKIM1; k=rsa; p='.$dkimPublicKey : '', 'priority' => null, 'purpose' => 'DKIM signature verification'],
            ['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@'.$domain.'; adkim=r; aspf=r', 'priority' => null, 'purpose' => 'Monitor SPF/DKIM alignment'],
        ];
    }
}
