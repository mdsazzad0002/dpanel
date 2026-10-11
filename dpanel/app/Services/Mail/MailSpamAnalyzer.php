<?php

namespace App\Services\Mail;

use App\Support\MailText;
use DOMDocument;
use DOMElement;

/**
 * Scores a message for spam and phishing the way SpamAssassin does: each
 * rule that matches adds points, and the total decides the verdict. It
 * reads only the message source and the header analysis (no DNS or
 * network lookups), so it can run whenever the Analysis tab is opened.
 */
class MailSpamAnalyzer
{
    /** Same thresholds as SpamAssassin's default required_score. */
    public const SPAM_SCORE = 5.0;

    public const SUSPICIOUS_SCORE = 2.5;

    private const MAX_PARTS = 50;

    private const PHRASES = [
        'act now', 'action required', 'urgent', 'immediately', 'limited time', 'expires today', 'last chance',
        'verify your account', 'confirm your account', 'update your account', 'account suspended', 'account has been suspended',
        'account will be closed', 'unusual activity', 'unusual sign-in', 'password expire', 'reset your password',
        'confirm your identity', 'validate your', 'security alert', 'you have won', 'you won', 'winner', 'congratulations',
        'lottery', 'prize', 'claim your', 'free money', '100% free', 'risk-free', 'no cost', 'double your', 'earn money',
        'work from home', 'make money', 'cash bonus', 'guaranteed', 'bitcoin', 'crypto', 'investment opportunity',
        'wire transfer', 'western union', 'gift card', 'inheritance', 'beneficiary', 'dear friend', 'dear customer',
        'dear user', 'click here', 'click below', 'unsubscribe here', 'this is not spam', 'weight loss', 'viagra', 'casino',
    ];

    /** Brands most often named in phishing display names. */
    private const BRANDS = [
        'paypal', 'apple', 'icloud', 'microsoft', 'outlook', 'office365', 'amazon', 'netflix', 'google', 'gmail',
        'facebook', 'instagram', 'whatsapp', 'dhl', 'fedex', 'ups', 'usps', 'bank', 'visa', 'mastercard', 'binance',
        'coinbase', 'docusign', 'dropbox', 'linkedin', 'bkash', 'nagad',
    ];

    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'is.gd', 'ow.ly', 'cutt.ly', 'rb.gy', 'shorturl.at', 'tiny.cc', 'buff.ly', 'rebrand.ly', 's.id'];

    private const FREEMAIL = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'aol.com', 'mail.ru', 'yandex.ru', 'gmx.com', 'proton.me', 'protonmail.com', 'icloud.com', 'zoho.com'];

    private const DANGEROUS_EXTENSIONS = ['exe', 'scr', 'js', 'jse', 'vbs', 'vbe', 'bat', 'cmd', 'com', 'pif', 'jar', 'msi', 'ps1', 'hta', 'lnk', 'iso', 'img', 'wsf', 'cpl', 'reg'];

    private const MACRO_EXTENSIONS = ['docm', 'xlsm', 'pptm', 'dotm', 'xlam'];

    private const ARCHIVE_EXTENSIONS = ['zip', 'rar', '7z', 'gz', 'tar', 'cab', 'ace'];

    /**
     * @param  string  $raw  the message source (may be cut off for large messages)
     * @param  array<string, mixed>  $headers  result of MailMessageAnalyzer::analyze()
     * @return array{score: float, verdict: string, rules: array<int, array{name: string, points: float, description: string}>, links: array{count: int, domains: array<int, string>}, attachments: array<int, array{name: string, risk: string}>}
     */
    public function analyze(string $raw, array $headers): array
    {
        $parts = $this->parts($raw);
        $text = implode("\n", array_column(array_filter($parts, fn ($p) => $p['type'] === 'text/plain' && $p['filename'] === ''), 'body'));
        $html = implode("\n", array_column(array_filter($parts, fn ($p) => $p['type'] === 'text/html' && $p['filename'] === ''), 'body'));
        $attachments = array_values(array_filter($parts, fn ($p) => $p['filename'] !== ''));

        $rules = [];
        $hit = function (string $name, float $points, string $description) use (&$rules): void {
            $rules[] = ['name' => $name, 'points' => $points, 'description' => $description];
        };

        $this->authRules($headers, $hit);
        $this->headerRules($headers, $hit);
        $links = $this->bodyRules($text, $html, (string) ($headers['summary']['subject'] ?? ''), $hit);
        $attachmentList = $this->attachmentRules($attachments, $hit);

        usort($rules, fn (array $a, array $b): int => $b['points'] <=> $a['points']);
        $score = round(array_sum(array_column($rules, 'points')), 1);

        return [
            'score' => $score,
            'verdict' => $score >= self::SPAM_SCORE ? 'spam' : ($score >= self::SUSPICIOUS_SCORE ? 'suspicious' : 'clean'),
            'rules' => $rules,
            'links' => $links,
            'attachments' => $attachmentList,
        ];
    }

    /**
     * @param  array<string, mixed>  $h
     */
    private function authRules(array $h, callable $hit): void
    {
        $spf = $h['auth']['spf']['result'] ?? 'none';
        $dmarc = $h['auth']['dmarc']['result'] ?? 'none';
        $dkim = $h['auth']['dkim'] ?? [];

        if ($dmarc === 'fail') {
            $hit('DMARC_FAIL', 4.0, 'DMARC failed: the From domain does not vouch for this message.');
        }
        if ($spf === 'fail') {
            $hit('SPF_FAIL', 3.0, 'SPF failed: the sending server may not send for this domain.');
        } elseif ($spf === 'softfail') {
            $hit('SPF_SOFTFAIL', 1.5, 'SPF soft fail: the domain does not expect mail from this server.');
        }
        if (collect($dkim)->contains(fn (array $sig): bool => in_array($sig['result'], ['fail', 'permerror'], true))) {
            $hit('DKIM_INVALID', 2.5, 'A DKIM signature did not verify: the message was changed after signing.');
        } elseif ($dkim === []) {
            $hit('DKIM_MISSING', 1.0, 'The message is not DKIM-signed.');
        }
        if (($h['summary']['return_path'] ?? '') !== '' && ! ($h['alignment']['spf'] ?? false) && ! ($h['alignment']['dkim'] ?? false)) {
            $hit('NOT_ALIGNED', 1.0, 'Neither SPF nor DKIM matches the From domain.');
        }
        if (($h['spam']['flag'] ?? '') === 'yes') {
            $hit('UPSTREAM_SPAM', 3.0, 'An earlier mail server already marked it as spam.');
        }
    }

    /**
     * @param  array<string, mixed>  $h
     */
    private function headerRules(array $h, callable $hit): void
    {
        $summary = $h['summary'] ?? [];
        $from = (string) ($summary['from'] ?? '');
        $fromAddress = $this->address($from);
        $fromDomain = $this->domainOf($fromAddress);
        $displayName = strtolower(trim(preg_replace('/<[^>]*>/', '', $from) ?? '', " \"'"));
        $headerKeys = array_map(fn (array $x): string => strtolower($x['name']), $h['headers'] ?? []);

        if (($summary['message_id'] ?? '') === '') {
            $hit('NO_MESSAGE_ID', 1.5, 'No Message-ID header; real mail clients always add one.');
        }
        $date = (string) ($summary['date'] ?? '');
        if ($date === '') {
            $hit('NO_DATE', 1.0, 'No Date header.');
        } elseif (($time = strtotime($date)) !== false && $time > time() + 86400) {
            $hit('DATE_IN_FUTURE', 1.5, 'The Date header is in the future.');
        }

        // "PayPal Support <alerts@random.example>" or "service@paypal.com <x@evil.test>".
        if (preg_match('/[\w.+-]+@([\w-]+\.[\w.-]+)/', $displayName, $m) && $fromDomain !== '' && strtolower($m[1]) !== $fromDomain) {
            $hit('FROM_NAME_SPOOF', 2.5, "The sender name shows another address ({$m[0]}) than the real one ({$fromAddress}).");
        } else {
            foreach (self::BRANDS as $brand) {
                if (preg_match('/\b'.preg_quote($brand, '/').'\b/', $displayName) && ! str_contains($fromDomain, $brand)) {
                    $hit('BRAND_IMPERSONATION', 2.5, "The sender name mentions \"{$brand}\" but the mail comes from {$fromDomain}.");
                    break;
                }
            }
        }

        $replyTo = $this->address((string) ($summary['reply_to'] ?? ''));
        $replyDomain = $this->domainOf($replyTo);
        if ($replyDomain !== '' && $fromDomain !== '' && $replyDomain !== $fromDomain) {
            in_array($replyDomain, self::FREEMAIL, true) && ! in_array($fromDomain, self::FREEMAIL, true)
                ? $hit('REPLYTO_FREEMAIL', 2.0, "Replies go to a free mailbox ({$replyTo}), not to {$fromDomain}.")
                : $hit('REPLYTO_DIFFERS', 1.0, "Replies go to another domain ({$replyDomain}).");
        }

        $to = strtolower((string) ($summary['to'] ?? ''));
        if ($to === '' || str_contains($to, 'undisclosed')) {
            $hit('NO_RECIPIENT', 1.0, 'No visible recipient (sent to undisclosed recipients).');
        }

        $subject = (string) ($summary['subject'] ?? '');
        $letters = preg_replace('/[^\p{L}]/u', '', $subject) ?? '';
        if (mb_strlen($letters) >= 10 && mb_strtoupper($letters) === $letters && mb_strtolower($letters) !== $letters) {
            $hit('SUBJECT_ALL_CAPS', 1.0, 'The subject is written in capitals.');
        }
        if (preg_match('/[!$€£]{3,}|!{2,}.*!{2,}/u', $subject)) {
            $hit('SUBJECT_PUNCTUATION', 1.0, 'The subject uses runs of !!! or $$$.');
        }
        if (preg_match('/^\s*(re|fw|fwd)\s*:/i', $subject) && ! in_array('in-reply-to', $headerKeys, true) && ! in_array('references', $headerKeys, true)) {
            $hit('FAKE_REPLY', 1.5, 'The subject starts with Re:/Fwd: but the message is not a reply to anything.');
        }
    }

    /**
     * @return array{count: int, domains: array<int, string>}
     */
    private function bodyRules(string $text, string $html, string $subject, callable $hit): array
    {
        if ($html !== '' && trim($text) === '') {
            $hit('HTML_ONLY', 0.5, 'HTML without a plain-text version.');
        }

        $visible = trim($text !== '' ? $text : MailText::htmlToText($html));
        $haystack = mb_strtolower($subject."\n".$visible);
        $phrases = array_values(array_filter(self::PHRASES, fn (string $p): bool => (bool) preg_match('/(?<![\w-])'.preg_quote($p, '/').'(?![\w-])/u', $haystack)));
        if ($phrases !== []) {
            $points = min(3.0, 0.5 * count($phrases));
            $hit('SPAM_PHRASES', $points, 'Typical spam/phishing wording: "'.implode('", "', array_slice($phrases, 0, 6)).'".');
        }

        $links = $this->links($html, $text);
        $domains = array_values(array_unique(array_filter(array_map(fn (array $l): string => $l['host'], $links))));

        foreach ($links as $link) {
            if ($link['shown_host'] !== '' && $link['host'] !== '' && $this->baseDomain($link['shown_host']) !== $this->baseDomain($link['host'])) {
                $hit('LINK_MISMATCH', 3.0, "A link shows {$link['shown_host']} but opens {$link['host']}.");
                break;
            }
        }
        foreach ($domains as $host) {
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$|^\[/', $host)) {
                $hit('LINK_IP_ADDRESS', 2.0, "A link points to a bare IP address ({$host}) instead of a domain.");
                break;
            }
        }
        $shorteners = array_values(array_intersect($domains, self::SHORTENERS));
        if ($shorteners !== []) {
            $hit('LINK_SHORTENER', 1.5, 'Links are hidden behind a URL shortener ('.implode(', ', $shorteners).').');
        }
        if (count($links) > 25) {
            $hit('MANY_LINKS', 0.5, count($links).' links in one message.');
        }

        if ($html !== '') {
            $images = preg_match_all('/<img\b/i', $html);
            if ($images > 0 && mb_strlen($visible) < 200) {
                $hit('IMAGE_ONLY', 1.5, 'Mostly images with almost no text, a common way to hide spam from filters.');
            }
            if (preg_match('/display\s*:\s*none|font-size\s*:\s*0(px|pt)?\s*[;"\']|visibility\s*:\s*hidden/i', $html)) {
                // Newsletters hide their preview line this way too, so it weighs little.
                $hit('HIDDEN_TEXT', 0.5, 'The HTML contains hidden text.');
            }
            if (preg_match('/<form\b/i', $html)) {
                $hit('HTML_FORM', 1.5, 'The message contains a form; legitimate senders never ask you to fill one in by mail.');
            }
        }

        return ['count' => count($links), 'domains' => array_slice($domains, 0, 15)];
    }

    /**
     * @param  array<int, array{type: string, filename: string, body: string}>  $attachments
     * @return array<int, array{name: string, risk: string}>
     */
    private function attachmentRules(array $attachments, callable $hit): array
    {
        $list = [];
        $flagged = ['dangerous' => false, 'double' => false, 'macro' => false, 'archive' => false];
        foreach ($attachments as $attachment) {
            $name = $attachment['filename'];
            $extensions = array_map('strtolower', array_slice(explode('.', $name), 1));
            $last = end($extensions) ?: '';
            $risk = 'none';

            if (count($extensions) >= 2 && in_array($last, self::DANGEROUS_EXTENSIONS, true) && in_array($extensions[count($extensions) - 2], ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'png', 'txt'], true)) {
                $risk = 'high';
                if (! $flagged['double']) {
                    $hit('DOUBLE_EXTENSION', 3.0, "\"{$name}\" disguises a program as a document.");
                    $flagged['double'] = true;
                }
            }
            if (in_array($last, self::DANGEROUS_EXTENSIONS, true)) {
                $risk = 'high';
                if (! $flagged['dangerous']) {
                    $hit('DANGEROUS_ATTACHMENT', 4.0, "\"{$name}\" can run code on your computer.");
                    $flagged['dangerous'] = true;
                }
            } elseif (in_array($last, self::MACRO_EXTENSIONS, true)) {
                $risk = 'medium';
                if (! $flagged['macro']) {
                    $hit('MACRO_ATTACHMENT', 2.0, "\"{$name}\" is an Office file with macros.");
                    $flagged['macro'] = true;
                }
            } elseif (in_array($last, self::ARCHIVE_EXTENSIONS, true)) {
                $risk = 'low';
                if (! $flagged['archive']) {
                    $hit('ARCHIVE_ATTACHMENT', 1.0, "\"{$name}\" is an archive, which hides its contents from scanners.");
                    $flagged['archive'] = true;
                }
            }
            $list[] = ['name' => $name, 'risk' => $risk];
        }

        return $list;
    }

    /**
     * Links with the host they open and, when the link text is itself a
     * URL or domain, the host it shows.
     *
     * @return array<int, array{host: string, shown_host: string}>
     */
    private function links(string $html, string $text): array
    {
        $links = [];
        if ($html !== '') {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            foreach ($document->getElementsByTagName('a') as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }
                $href = trim($anchor->getAttribute('href'));
                if (! preg_match('#^https?://#i', $href)) {
                    continue;
                }
                $shown = trim($anchor->textContent);
                $links[] = [
                    'host' => $this->host($href),
                    'shown_host' => preg_match('#^(https?://)?([a-z0-9-]+\.)+[a-z]{2,}(/\S*)?$#i', $shown) ? $this->host(preg_match('#^https?://#i', $shown) ? $shown : 'http://'.$shown) : '',
                ];
            }
        } elseif (preg_match_all('#https?://[^\s<>"\')]+#i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $links[] = ['host' => $this->host($url), 'shown_host' => ''];
            }
        }

        return $links;
    }

    private function host(string $url): string
    {
        return strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    }

    /** www.paypal.com and paypal.com are the same site for the mismatch rule. */
    private function baseDomain(string $host): string
    {
        $labels = explode('.', preg_replace('/^www\./', '', $host) ?? $host);

        return implode('.', array_slice($labels, -2));
    }

    private function address(string $value): string
    {
        return preg_match('/<([^<>]*)>/', $value, $m) ? strtolower(trim($m[1])) : strtolower(trim($value));
    }

    private function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }

    /**
     * Minimal MIME walker: every leaf part with its type, file name and
     * decoded body (text parts only; attachment bodies are not needed).
     *
     * @return array<int, array{type: string, filename: string, body: string}>
     */
    public function parts(string $raw, int $depth = 0): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $split = strpos($raw, "\n\n");
        $head = $split === false ? $raw : substr($raw, 0, $split);
        $body = $split === false ? '' : substr($raw, $split + 2);
        $headers = $this->partHeaders($head);

        $contentType = $headers['content-type'] ?? 'text/plain';
        $type = strtolower(trim(explode(';', $contentType)[0])) ?: 'text/plain';
        $disposition = $headers['content-disposition'] ?? '';
        $filename = $this->parameter($disposition, 'filename') ?: $this->parameter($contentType, 'name');

        if (str_starts_with($type, 'multipart/') && $depth < 5) {
            $boundary = $this->parameter($contentType, 'boundary');
            if ($boundary === '') {
                return [];
            }
            $parts = [];
            $chunks = explode("\n--".$boundary, "\n".$body);
            array_shift($chunks); // preamble
            foreach ($chunks as $chunk) {
                if (str_starts_with($chunk, '--') || count($parts) >= self::MAX_PARTS) {
                    break;
                }
                $parts = array_merge($parts, $this->parts(ltrim(substr($chunk, strpos($chunk, "\n") ?: 0), "\n"), $depth + 1));
            }

            return array_slice($parts, 0, self::MAX_PARTS);
        }

        $isText = in_array($type, ['text/plain', 'text/html'], true) && $filename === '';
        if ($isText) {
            $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));
            $body = match ($encoding) {
                'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
                'quoted-printable' => quoted_printable_decode($body),
                default => $body,
            };
            $body = MailText::toUtf8($body, $this->parameter($contentType, 'charset') ?: null);
        }

        return [['type' => $type, 'filename' => $this->decodeWords($filename), 'body' => $isText ? $body : '']];
    }

    /**
     * @return array<string, string>
     */
    private function partHeaders(string $head): array
    {
        $headers = [];
        $current = null;
        foreach (explode("\n", $head) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current] .= ' '.trim($line);
            } elseif (($colon = strpos($line, ':')) !== false) {
                $current = strtolower(trim(substr($line, 0, $colon)));
                $headers[$current] = trim(substr($line, $colon + 1));
            }
        }

        return $headers;
    }

    private function parameter(string $header, string $name): string
    {
        // name="value", name=value, and RFC 2231 name*=utf-8''value.
        if (preg_match('/(?:^|;)\s*'.preg_quote($name, '/').'\*?=\s*(?:"([^"]*)"|([^;\s]+))/i', $header, $m)) {
            $value = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if (preg_match("/^[\\w-]+'[\\w-]*'(.*)$/", $value, $encoded)) {
                $value = rawurldecode($encoded[1]);
            }

            return trim($value);
        }

        return '';
    }

    private function decodeWords(string $value): string
    {
        if ($value === '' || ! str_contains($value, '=?')) {
            return $value;
        }
        $decoded = function_exists('iconv_mime_decode') ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;

        return is_string($decoded) && $decoded !== '' ? $decoded : $value;
    }
}
