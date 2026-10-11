<?php

namespace App\Services\Mail;

use App\Support\MailText;

/**
 * Reads a message's header block the way Gmail's "Show original" does:
 * SPF/DKIM/DMARC verdicts, which service sent it, the relay path with
 * delays and TLS, spam scores and bulk-mail headers. Header text only, no
 * network lookups, so it costs nothing beyond parsing.
 */
class MailMessageAnalyzer
{
    /**
     * Sending services, matched against Received hosts, DKIM d=, Message-ID
     * and vendor headers. First match wins, so specific ESPs come before the
     * big mailbox providers whose infrastructure they sometimes relay through.
     *
     * @var array<string, array{hosts?: array<int, string>, headers?: array<int, string>}>
     */
    private const PROVIDERS = [
        'Amazon SES' => ['hosts' => ['amazonses.com'], 'headers' => ['x-ses-outgoing']],
        'SendGrid' => ['hosts' => ['sendgrid.net'], 'headers' => ['x-sg-eid']],
        'Mailgun' => ['hosts' => ['mailgun.org', 'mailgun.net'], 'headers' => ['x-mailgun-sid']],
        'Mailchimp / Mandrill' => ['hosts' => ['mcsv.net', 'mandrillapp.com', 'mcdlv.net', 'rsgsv.net'], 'headers' => ['x-mc-user', 'x-mandrill-user']],
        'Postmark' => ['hosts' => ['mtasv.net', 'postmarkapp.com'], 'headers' => ['x-pm-message-id']],
        'Brevo (Sendinblue)' => ['hosts' => ['sendinblue.com', 'brevo.com'], 'headers' => ['x-sib-id']],
        'Zoho Mail' => ['hosts' => ['zoho.com', 'zohomail.com']],
        'Proton Mail' => ['hosts' => ['protonmail.ch', 'proton.me']],
        'iCloud Mail' => ['hosts' => ['icloud.com', 'me.com', 'mail.apple.com']],
        'Yahoo Mail' => ['hosts' => ['yahoo.com', 'yahoodns.net', 'aol.com']],
        'Outlook / Microsoft 365' => ['hosts' => ['outlook.com', 'protection.outlook.com', 'hotmail.com', 'office365.com'], 'headers' => ['x-ms-exchange-organization-authas', 'x-microsoft-antispam']],
        'Gmail / Google Workspace' => ['hosts' => ['google.com', 'gmail.com', 'googlemail.com'], 'headers' => ['x-gm-message-state', 'x-google-smtp-source']],
    ];

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $rawHeaders): array
    {
        $headers = $this->parseHeaders($rawHeaders);
        $get = fn (string $name): ?string => $this->first($headers, $name);

        $from = $this->address((string) $get('from'));
        $returnPath = $this->address((string) $get('return-path'));
        $auth = $this->authentication($headers);
        $hops = $this->hops($headers);

        $fromDomain = $this->domainOf($from);
        $alignment = [
            'spf' => $fromDomain !== '' && $this->sameOrg($fromDomain, $this->domainOf($returnPath)),
            'dkim' => $fromDomain !== '' && collect($auth['dkim'])->contains(
                fn (array $sig): bool => $sig['result'] === 'pass' && $this->sameOrg($fromDomain, $sig['domain'])
            ),
        ];

        $analysis = [
            'summary' => [
                'message_id' => trim((string) $get('message-id')),
                'date' => trim((string) $get('date')),
                'from' => (string) $get('from'),
                'to' => (string) $get('to'),
                'subject' => (string) $get('subject'),
                'return_path' => $returnPath,
                'reply_to' => (string) $get('reply-to'),
                'mailer' => (string) ($get('x-mailer') ?? $get('user-agent') ?? ''),
            ],
            'provider' => $this->provider($headers, $hops, $auth['dkim']),
            'auth' => $auth,
            'alignment' => $alignment,
            'hops' => $hops,
            'tls' => $this->pathEncrypted($hops),
            'total_delay' => $this->totalDelay($hops),
            'spam' => $this->spam($headers),
            'bulk' => [
                'list_unsubscribe' => (string) ($get('list-unsubscribe') ?? ''),
                'one_click' => stripos((string) $get('list-unsubscribe-post'), 'one-click') !== false,
                'precedence' => strtolower(trim((string) ($get('precedence') ?? ''))),
                'list_id' => (string) ($get('list-id') ?? ''),
            ],
            'sandbox' => (string) ($get('x-dpanel-sandbox') ?? ''),
            'headers' => array_map(fn (array $h): array => ['name' => $h['name'], 'value' => $h['value']], $headers),
        ];
        $analysis['warnings'] = $this->warnings($analysis);

        return $analysis;
    }

    /**
     * Unfold continuation lines and decode encoded words.
     *
     * @return array<int, array{name: string, key: string, value: string}>
     */
    public function parseHeaders(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        // Only the header block, in case a full message is passed in.
        $end = strpos($raw, "\n\n");
        if ($end !== false) {
            $raw = substr($raw, 0, $end);
        }

        $headers = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $headers !== []) {
                $headers[count($headers) - 1]['value'] .= ' '.trim($line);

                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = trim(substr($line, 0, $colon));
            $headers[] = ['name' => $name, 'key' => strtolower($name), 'value' => trim(substr($line, $colon + 1))];
        }

        foreach ($headers as &$header) {
            $header['value'] = $this->decode($header['value']);
        }

        return $headers;
    }

    /**
     * @param  array<int, array{name: string, key: string, value: string}>  $headers
     * @return array<int, string>
     */
    private function all(array $headers, string $name): array
    {
        return array_values(array_map(
            fn (array $h): string => $h['value'],
            array_filter($headers, fn (array $h): bool => $h['key'] === $name),
        ));
    }

    /**
     * @param  array<int, array{name: string, key: string, value: string}>  $headers
     */
    private function first(array $headers, string $name): ?string
    {
        return $this->all($headers, $name)[0] ?? null;
    }

    /**
     * The topmost Authentication-Results was added by the receiving server;
     * lower ones may come from earlier hops (or be forged), so they only
     * fill in what the top one does not mention.
     *
     * @param  array<int, array{name: string, key: string, value: string}>  $headers
     * @return array{spf: array{result: string, detail: string}, dkim: array<int, array{result: string, domain: string, selector: string}>, dmarc: array{result: string, detail: string}, arc: string}
     */
    private function authentication(array $headers): array
    {
        $spf = ['result' => 'none', 'detail' => ''];
        $dmarc = ['result' => 'none', 'detail' => ''];
        $arc = '';
        $dkim = [];

        foreach ($this->all($headers, 'authentication-results') as $value) {
            // Comments may contain ';' ("(2048-bit key; unprotected)").
            $value = preg_replace('/\([^()]*\)/', '', $value) ?? $value;
            if ($spf['result'] === 'none' && preg_match('/\bspf=(\w+)([^;]*)/i', $value, $m)) {
                $spf = ['result' => strtolower($m[1]), 'detail' => trim($m[2])];
            }
            if ($dmarc['result'] === 'none' && preg_match('/\bdmarc=(\w+)([^;]*)/i', $value, $m)) {
                $dmarc = ['result' => strtolower($m[1]), 'detail' => trim($m[2])];
            }
            if ($arc === '' && preg_match('/\barc=(\w+)/i', $value, $m)) {
                $arc = strtolower($m[1]);
            }
            if ($dkim === [] && preg_match_all('/\bdkim=(\w+)([^;]*)/i', $value, $all, PREG_SET_ORDER)) {
                foreach ($all as $m) {
                    preg_match('/header\.(?:d|i)=@?([^\s;]+)/i', $m[2], $d);
                    preg_match('/header\.s=([^\s;]+)/i', $m[2], $sel);
                    $dkim[] = [
                        'result' => strtolower($m[1]),
                        'domain' => strtolower(ltrim($d[1] ?? '', '@')),
                        'selector' => $sel[1] ?? '',
                    ];
                }
            }
        }

        if ($spf['result'] === 'none' && ($received = $this->first($headers, 'received-spf')) !== null) {
            preg_match('/^\s*(\w+)\s*(.*)$/', $received, $m);
            $spf = ['result' => strtolower($m[1] ?? 'none'), 'detail' => trim($m[2] ?? '')];
        }

        // Signed but no verifier said anything: list the signatures as unchecked.
        if ($dkim === []) {
            foreach ($this->all($headers, 'dkim-signature') as $signature) {
                $dkim[] = [
                    'result' => 'unverified',
                    'domain' => strtolower($this->tag($signature, 'd')),
                    'selector' => $this->tag($signature, 's'),
                ];
            }
        }

        return ['spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'arc' => $arc];
    }

    private function tag(string $value, string $tag): string
    {
        return preg_match('/(?:^|;)\s*'.preg_quote($tag, '/').'=([^;]*)/i', $value, $m) ? trim($m[1]) : '';
    }

    /**
     * Received headers in the order the message travelled (oldest first),
     * with the delay each relay added.
     *
     * @param  array<int, array{name: string, key: string, value: string}>  $headers
     * @return array<int, array{from: string, by: string, with: string, tls: bool, date: string, delay: int|null}>
     */
    private function hops(array $headers): array
    {
        $hops = [];
        $previous = null;
        foreach (array_reverse($this->all($headers, 'received')) as $value) {
            $semicolon = strrpos($value, ';');
            $date = $semicolon !== false ? trim(substr($value, $semicolon + 1)) : '';
            $route = $semicolon !== false ? substr($value, 0, $semicolon) : $value;
            $time = $date !== '' ? strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $date) ?: $date) : false;

            preg_match('/\bwith\s+(\S+)/i', $route, $with);
            $protocol = strtoupper($with[1] ?? '');
            $hops[] = [
                'from' => $this->clause($route, 'from'),
                'by' => $this->clause($route, 'by'),
                'with' => $protocol,
                // ESMTPS / ESMTPSA / UTF8SMTPS and an explicit TLS note all mean an encrypted hop.
                'tls' => (bool) preg_match('/^(E|UTF8)SMTPS/', $protocol) || (bool) preg_match('/\b(TLSv?1|version=TLS)/i', $route),
                'date' => $date,
                'delay' => $time !== false && $previous !== null ? max(0, $time - $previous) : null,
            ];
            if ($time !== false) {
                $previous = $time;
            }
        }

        return $hops;
    }

    /**
     * Whether every hop between servers used TLS. Hops with no "from" are
     * hand-offs inside one system (Gmail's "Received: by ... with SMTP"),
     * and loopback hops never touch the network, so neither counts.
     *
     * @param  array<int, array<string, mixed>>  $hops
     */
    private function pathEncrypted(array $hops): ?bool
    {
        $network = array_filter($hops, fn (array $hop): bool => $hop['from'] !== ''
            && ! preg_match('/^(localhost|127\.0\.0\.1|\[?::1\]?)\b|\[(127\.0\.0\.1|::1|IPv6:::1)\]/i', $hop['from']));

        return $network === [] ? null : collect($network)->every(fn (array $hop): bool => $hop['tls']);
    }

    private function clause(string $route, string $word): string
    {
        // "from host (helo [ip])" up to the next keyword.
        if (! preg_match('/\b'.$word.'\s+(.+?)(?=\s+\b(?:from|by|via|with|id|for)\b\s|$)/is', $route, $m)) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $hops
     */
    private function totalDelay(array $hops): ?int
    {
        $delays = array_filter(array_column($hops, 'delay'), fn ($d): bool => $d !== null);

        return $delays === [] ? null : (int) array_sum($delays);
    }

    /**
     * @param  array<int, array{name: string, key: string, value: string}>  $headers
     * @param  array<int, array<string, mixed>>  $hops
     * @param  array<int, array{result: string, domain: string, selector: string}>  $dkim
     */
    private function provider(array $headers, array $hops, array $dkim): string
    {
        $keys = array_column($headers, 'key');
        // The first two hops are the sender's side; later ones are ours.
        $haystack = strtolower(implode(' ', array_merge(
            array_map(fn (array $hop): string => $hop['from'].' '.$hop['by'], array_slice($hops, 0, 2)),
            array_column($dkim, 'domain'),
            [(string) $this->first($headers, 'message-id')],
        )));

        foreach (self::PROVIDERS as $name => $rule) {
            foreach ($rule['headers'] ?? [] as $header) {
                if (in_array($header, $keys, true)) {
                    return $name;
                }
            }
            foreach ($rule['hosts'] ?? [] as $host) {
                if (preg_match('/(^|[\s.@(\[])'.preg_quote($host, '/').'\b/', $haystack)) {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * @param  array<int, array{name: string, key: string, value: string}>  $headers
     * @return array{flag: string, score: string, detail: string}
     */
    private function spam(array $headers): array
    {
        $status = (string) ($this->first($headers, 'x-spam-status') ?? '');
        $score = (string) ($this->first($headers, 'x-spam-score') ?? $this->first($headers, 'x-rspamd-score') ?? '');
        if ($score === '' && preg_match('/\b(?:score|hits)=(-?[\d.]+)/i', $status, $m)) {
            $score = $m[1];
        }
        $flag = strtolower(trim((string) ($this->first($headers, 'x-spam-flag') ?? '')));
        if ($flag === '' && $status !== '') {
            $flag = str_starts_with(strtolower(ltrim($status)), 'yes') ? 'yes' : 'no';
        }

        return ['flag' => $flag, 'score' => trim($score), 'detail' => $status];
    }

    /**
     * Plain-language problems, worst first.
     *
     * @param  array<string, mixed>  $a
     * @return array<int, string>
     */
    private function warnings(array $a): array
    {
        $warnings = [];
        $bad = ['fail', 'softfail', 'permerror', 'temperror'];

        if (in_array($a['auth']['dmarc']['result'], ['fail', 'permerror'], true)) {
            $warnings[] = 'DMARC failed: the sender domain does not vouch for this message. It may be spoofed.';
        }
        if (in_array($a['auth']['spf']['result'], $bad, true)) {
            $warnings[] = 'SPF '.$a['auth']['spf']['result'].': the sending server is not allowed to send for the Return-Path domain.';
        }
        foreach ($a['auth']['dkim'] as $sig) {
            if (in_array($sig['result'], $bad, true)) {
                $warnings[] = 'DKIM signature for '.($sig['domain'] ?: 'unknown domain').' did not verify: the message was changed or the key is wrong.';
            }
        }
        if ($a['auth']['dkim'] === []) {
            $warnings[] = 'No DKIM signature. Gmail and Outlook expect signed mail and may send it to spam.';
        }
        if ($a['summary']['return_path'] !== '' && ! $a['alignment']['spf'] && ! $a['alignment']['dkim']) {
            $warnings[] = 'Neither SPF nor DKIM aligns with the From domain, so DMARC cannot pass.';
        }
        if ($a['spam']['flag'] === 'yes') {
            $warnings[] = 'Marked as spam'.($a['spam']['score'] !== '' ? ' (score '.$a['spam']['score'].')' : '').'.';
        }
        if ($a['tls'] === false) {
            $warnings[] = 'At least one relay did not use TLS, so the message crossed the network unencrypted.';
        }
        $looksBulk = $a['bulk']['list_id'] !== '' || in_array($a['bulk']['precedence'], ['bulk', 'list'], true);
        if ($looksBulk && $a['bulk']['list_unsubscribe'] === '') {
            $warnings[] = 'Bulk mail without List-Unsubscribe. Gmail and Yahoo require it for bulk senders.';
        } elseif ($looksBulk && ! $a['bulk']['one_click']) {
            $warnings[] = 'List-Unsubscribe has no one-click (List-Unsubscribe-Post) header, which Gmail and Yahoo require for bulk senders.';
        }
        if ($a['summary']['message_id'] === '') {
            $warnings[] = 'No Message-ID header; many receivers score this as spam.';
        }

        return $warnings;
    }

    private function address(string $value): string
    {
        if (preg_match('/<([^<>]*)>/', $value, $m)) {
            return strtolower(trim($m[1]));
        }

        return strtolower(trim(preg_replace('/\s*\(.*\)\s*/', '', $value) ?? $value));
    }

    private function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }

    /**
     * Relaxed DMARC alignment: same organizational domain. Approximated
     * without the public suffix list: the last two labels, or three when
     * the second-level label is a common public one (example.co.uk).
     */
    private function sameOrg(string $a, string $b): bool
    {
        return $a !== '' && $b !== '' && $this->orgDomain($a) === $this->orgDomain($b);
    }

    private function orgDomain(string $domain): string
    {
        $labels = explode('.', strtolower(trim($domain, '.')));
        $count = count($labels);
        if ($count <= 2) {
            return implode('.', $labels);
        }
        $take = strlen($labels[$count - 1]) === 2 && in_array($labels[$count - 2], ['co', 'com', 'net', 'org', 'gov', 'ac', 'edu', 'ne', 'or'], true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    private function decode(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return MailText::toUtf8($value);
        }
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($value) : $value;
    }
}
