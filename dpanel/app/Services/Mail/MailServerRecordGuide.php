<?php

namespace App\Services\Mail;

/**
 * One table of every record a working mail server needs: the exact value to
 * set, where it is set, and — for the records that belong to this server
 * rather than to one domain's zone — what public DNS answers right now.
 *
 * Mail Health shows this so an admin can see the correct setup in one place,
 * including the IPv6 side that is easy to forget: Postfix prefers IPv6
 * whenever the receiver has an AAAA record, and Gmail rejects that mail
 * unless the IPv6 address has forward-confirmed reverse DNS.
 */
class MailServerRecordGuide
{
    public function __construct(
        private readonly MailDnsRecords $records,
        private readonly MailDnsVerifier $verifier,
    ) {
    }

    /**
     * @return array{host: string, ipv4: string, ipv6: string, rows: array<int, array<string, mixed>>}
     */
    public function table(bool $checkLive = true): array
    {
        $host = $this->records->mailHost();
        $ipv4 = $this->records->serverIp();
        $ipv6 = $this->records->serverIpv6();
        // Nothing to look up while the server's own name or address is
        // unknown; the rows then just state what to set.
        $checks = $checkLive && $host !== '' && $ipv4 !== '' ? $this->verifier->serverChecks() : [];

        return [
            'host' => $host,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'rows' => [
                ...$this->serverRows($host, $ipv4, $ipv6, $checks),
                ...$this->domainRows($host),
            ],
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $checks
     * @return array<int, array<string, mixed>>
     */
    private function serverRows(string $host, string $ipv4, string $ipv6, array $checks): array
    {
        $dnsZone = 'DNS zone of '.($host !== '' ? $host : 'the mail host');

        $rows = [
            $this->row('host', $checks, [
                'type' => 'A',
                'name' => $host,
                'value' => $ipv4,
                'where' => $dnsZone,
                'purpose' => 'The name every domain points MX at must resolve to this server, DNS-only (no Cloudflare proxy).',
                'required' => true,
            ]),
            $this->row('ptr', $checks, [
                'type' => 'PTR',
                'name' => $ipv4,
                'value' => $host,
                'where' => 'Your server or IP provider panel (not DNS)',
                'purpose' => 'Gmail rejects mail from an IPv4 address whose PTR name does not resolve back to it.',
                'required' => true,
            ]),
        ];

        if ($ipv6 === '') {
            // Stated rather than hidden: an admin looking for the IPv6 rows
            // should see why there are none instead of wondering.
            $rows[] = $this->row('host6', [], [
                'type' => 'AAAA',
                'name' => $host,
                'value' => '',
                'where' => $dnsZone,
                'purpose' => 'This server has no public IPv6 address, so there is no AAAA or IPv6 PTR to set. Mail is sent and received over IPv4.',
                'required' => false,
            ]);

            return $rows;
        }

        $rows[] = $this->row('host6', $checks, [
            'type' => 'AAAA',
            'name' => $host,
            'value' => $ipv6,
            'where' => $dnsZone,
            'purpose' => 'Optional. Lets other servers deliver to this one over IPv6. A wrong AAAA is worse than none: mail stalls in their queue.',
            'required' => false,
        ]);
        $rows[] = $this->row('ptr6', $checks, [
            'type' => 'PTR',
            'name' => $ipv6,
            'value' => $host,
            'where' => 'Your server or IP provider panel (not DNS)',
            'purpose' => 'Needed before this server sends over IPv6. Until the PTR resolves back, the panel keeps outbound mail on IPv4.',
            'required' => false,
        ]);

        return $rows;
    }

    /**
     * The records each mail domain needs in its own zone. MX and SPF are the
     * same for every domain, so their real values are shown; DKIM and DMARC
     * are per domain and are generated in the Mail DNS Guide.
     *
     * @return array<int, array<string, mixed>>
     */
    private function domainRows(string $host): array
    {
        return [
            $this->row('mx', [], [
                'type' => 'MX',
                'name' => '@',
                'value' => $host,
                'priority' => 10,
                'where' => "Each mail domain's DNS zone",
                'purpose' => 'Receives mail for the domain.',
                'required' => true,
            ]),
            $this->row('spf', [], [
                'type' => 'TXT',
                'name' => '@',
                'value' => $this->records->spf(),
                'where' => "Each mail domain's DNS zone",
                'purpose' => 'Authorizes this server to send for the domain (SPF). Exactly one SPF record per domain.',
                'required' => true,
            ]),
            $this->row('dkim', [], [
                'type' => 'TXT',
                'name' => '<selector>._domainkey',
                'value' => 'v=DKIM1; k=rsa; p=<public key>',
                'where' => "Each mail domain's DNS zone",
                'purpose' => "Signature verification. The key is per domain: generate it in the Mail DNS Guide, which then shows the record's real value.",
                'required' => true,
            ]),
            $this->row('dmarc', [], [
                'type' => 'TXT',
                'name' => '_dmarc',
                'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@<domain>; adkim=r; aspf=r',
                'where' => "Each mail domain's DNS zone",
                'purpose' => 'Gmail requires DMARC for bulk senders. Start at p=none and tighten it once SPF and DKIM pass.',
                'required' => true,
            ]),
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $checks
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function row(string $key, array $checks, array $row): array
    {
        $check = $checks[$key] ?? null;

        return [
            'key' => $key,
            'priority' => null,
            'status' => $check['status'] ?? 'unknown',
            'found' => $check['found'] ?? '',
            'hint' => $check['hint'] ?? '',
            'checked' => $check !== null,
            ...$row,
        ];
    }
}
