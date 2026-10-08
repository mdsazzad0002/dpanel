<?php

namespace App\Services\Mail;

use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;

class MailDeliveryDiagnosticsService
{
    private const MAX_LOG_BYTES = 4_194_304;
    private const MAX_LOG_LINES = 5000;
    private const MAX_EVENTS = 120;
    // mail-log-tail.sh refuses anything above this.
    private const MAX_DOWNLOAD_LINES = 20000;

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        [$lines, $logSource] = $this->readLogLines();
        $parsed = $this->parseLogLines($lines);
        $queue = $this->queueSnapshot();
        $attempts = $parsed['stats']['sent'] + $parsed['stats']['bounced'] + $parsed['stats']['deferred'];
        $deliveryRate = $attempts > 0
            ? round(($parsed['stats']['sent'] / $attempts) * 100, 1)
            : null;

        return [
            'generated_at' => now()->toIso8601String(),
            'health_score' => $this->healthScore($parsed['stats'], $queue, $logSource),
            'delivery_rate' => $deliveryRate,
            'stats' => $parsed['stats'],
            'failures' => array_slice(array_reverse($parsed['failures']), 0, self::MAX_EVENTS),
            'spam_events' => array_slice(array_reverse($parsed['spam_events']), 0, self::MAX_EVENTS),
            'queue' => $queue,
            'diagnostics' => [
                'log_source' => $logSource,
                // stat() needs no read permission, so the size is known even when the content is root-only.
                'log_size_bytes' => $logSource !== null && is_file($logSource) ? (int) @filesize($logSource) : null,
                'lines_analyzed' => count($lines),
                'spam_engine' => $this->spamEngine($lines),
                'scope_note' => 'Metrics are calculated from the most recent available mail log sample.',
            ],
        ];
    }

    /**
     * The recent mail log for download — read the same way as the snapshot
     * (directly, else through the root tail script), with a larger window.
     *
     * @return array{content: string, source: string|null}
     */
    public function downloadLog(): array
    {
        [$lines, $source] = $this->readLogLines(self::MAX_DOWNLOAD_LINES);

        return [
            'content' => $lines === [] ? '' : implode("\n", $lines)."\n",
            'source' => $source,
        ];
    }

    /**
     * Archive (gzip) and empty the mail log.
     *
     * @return array{ok: bool, message: string}
     */
    public function clearLog(): array
    {
        $path = collect((array) config('serverpanel.mail.health_log_paths', []))
            ->first(fn ($candidate) => is_string($candidate) && is_file($candidate));
        if ($path === null) {
            return ['ok' => false, 'message' => 'No mail log file was found to clear.'];
        }

        try {
            $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/mail-log-clear.sh';
            $result = app(ScriptExecutionGateway::class)->execute($script, [$path], [], true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! $result['success'] || ! preg_match('/^BYTES_FREED=(\d+)$/m', $result['output'], $freed)) {
            return ['ok' => false, 'message' => 'Mail log could not be cleared: '.(trim($result['output']) ?: 'unknown error.')];
        }

        $archive = preg_match('/^ARCHIVE=(.+)$/m', $result['output'], $match) ? trim($match[1]) : '';

        return [
            'ok' => true,
            'message' => sprintf('Mail log cleared (%s freed).', $this->humanBytes((int) $freed[1]))
                .($archive !== '' ? ' Previous content archived to '.$archive.'.' : ''),
        ];
    }

    private function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024, 1).' KB',
            default => $bytes.' B',
        };
    }

    /** @return array{category: string, label: string, explanation: string, suggestion: string, severity: string, temporary: bool} */
    public function classifyFailure(string $reason, string $status = ''): array
    {
        $text = strtolower($reason.' '.$status);
        $rules = [
            ['mailbox_missing', 'Mailbox not found', 'The destination server says that the recipient mailbox does not exist.', 'Verify the recipient address and remove invalid addresses from the list.', 'high', false, ['user unknown', 'unknown user', 'no such user', 'mailbox unavailable', 'recipient address rejected', '5.1.1']],
            ['quota', 'Mailbox quota exceeded', 'The recipient mailbox or your mail storage has reached its limit.', 'Ask the recipient to free storage or increase the mailbox quota, then retry.', 'medium', true, ['quota exceeded', 'mailbox full', 'over quota', 'insufficient system storage', '5.2.2']],
            ['authentication', 'Authentication failed', 'The sending server could not authenticate with the configured credentials or relay policy.', 'Verify the mailbox username/password and ensure SMTP authentication is enabled.', 'high', false, ['authentication failed', 'sasl authentication', 'relay access denied', 'not permitted to relay', '5.7.8']],
            ['dns', 'DNS or domain problem', 'A required domain, MX record, or destination hostname could not be resolved.', 'Check the domain MX/A records and confirm that the destination domain is active.', 'high', true, ['domain not found', 'host not found', 'name service error', 'no mx', 'nxdomain', 'temporary lookup failure', '5.4.4']],
            ['ptr', 'Missing reverse DNS (PTR)', 'The destination rejected this server because its IP has no PTR record, or the PTR hostname does not resolve back to the same IP.', 'Ask the server/IP provider to set a PTR record for the sending IP that matches the mail hostname, and make sure that hostname has an A record pointing back to the IP.', 'high', false, ['ptr record', 'reverse dns', 'reverse lookup', '5.7.25']],
            ['reputation', 'IP or domain reputation blocked', 'The destination rejected this server because of a blocklist or poor sending reputation.', 'Check IP blocklists, PTR/rDNS, sending history, and request delisting where required.', 'high', false, ['blacklist', 'blocklist', 'listed in', 'reputation', 'spamhaus', 'barracuda', 'not authorized to send email directly', 'use the smtp relay']],
            ['authentication_policy', 'SPF, DKIM, or DMARC failed', 'The message did not satisfy the destination domain authentication policy.', 'Review SPF, enable DKIM signing, and confirm DMARC alignment in Mail DNS Guide.', 'high', false, ['spf fail', 'dkim fail', 'dmarc fail', 'authentication-results', 'unauthenticated email', '5.7.26']],
            ['spam', 'Rejected as spam', 'The receiving system classified the content or sender as spam.', 'Review the spam score/rules, remove suspicious links or attachments, and verify sender reputation.', 'high', false, ['spam detected', 'message considered spam', 'spam message rejected', 'spam content', 'unsolicited']],
            ['rate_limit', 'Sending rate limited', 'Too many messages or connections were sent in a short period.', 'Slow the sending rate and retry after the provider cooldown period.', 'medium', true, ['rate limit', 'too many messages', 'too many connections', 'throttl', '4.7.0']],
            ['tls', 'TLS negotiation failed', 'The sending and receiving servers could not establish a secure TLS connection.', 'Check the mail hostname certificate, TLS versions, and system time.', 'high', true, ['tls', 'ssl', 'certificate verify failed', 'handshake failure']],
            ['network', 'Connection or timeout problem', 'The destination server could not be reached or did not respond in time.', 'Check network/firewall connectivity and retry later; persistent failures may indicate a remote outage.', 'medium', true, ['connection timed out', 'connection refused', 'network is unreachable', 'lost connection', 'connect to', '4.4.1', '4.4.2']],
            ['policy', 'Recipient server policy rejection', 'The destination server rejected the message under its local security policy.', 'Read the server response, verify message headers/content, and contact the recipient provider if needed.', 'high', false, ['policy', 'access denied', 'blocked by', 'prohibited', '5.7.1']],
        ];

        foreach ($rules as [$category, $label, $explanation, $suggestion, $severity, $temporary, $needles]) {
            foreach ($needles as $needle) {
                // Enhanced status codes must match whole: "5.7.1" is not "5.7.10".
                $matched = preg_match('/^\d\.\d\.\d+$/', $needle) === 1
                    ? preg_match('/(?<![\d.])'.preg_quote($needle, '/').'(?![\d])/', $text) === 1
                    : str_contains($text, $needle);
                if ($matched) {
                    return compact('category', 'label', 'explanation', 'suggestion', 'severity', 'temporary');
                }
            }
        }

        $temporary = str_contains($text, 'deferred') || preg_match('/\b4\d\d\b|\b4\.\d\.\d\b/', $text) === 1;

        return [
            'category' => 'other',
            'label' => $temporary ? 'Temporary delivery failure' : 'Delivery rejected',
            'explanation' => $temporary
                ? 'Delivery was delayed by a response that may recover automatically.'
                : 'The destination rejected the message for a reason not yet classified.',
            'suggestion' => $temporary
                ? 'Allow Postfix to retry and inspect the response again if the message remains queued.'
                : 'Review the exact server response and verify the recipient, DNS, authentication, and sender reputation.',
            'severity' => $temporary ? 'medium' : 'high',
            'temporary' => $temporary,
        ];
    }

    /** @param array<int, string> $lines
     *  @return array{stats: array<string, int>, failures: array<int, array<string, mixed>>, spam_events: array<int, array<string, mixed>>}
     */
    private function parseLogLines(array $lines): array
    {
        $stats = ['sent' => 0, 'bounced' => 0, 'deferred' => 0, 'rejected' => 0, 'spam' => 0];
        $failures = [];
        $spamEvents = [];
        $senders = [];

        foreach ($lines as $line) {
            if (preg_match('/postfix\/[^\[]+\[\d+\]:\s+([A-Z0-9]+):\s+from=<([^>]*)>/i', $line, $match)) {
                $senders[strtoupper($match[1])] = $match[2];
            }

            if (preg_match('/postfix\/[^\[]+\[\d+\]:\s+([A-Z0-9]+):.*\bstatus=(sent|bounced|deferred)\b\s*\((.*)\)\s*$/i', $line, $match)) {
                $queueId = strtoupper($match[1]);
                $status = strtolower($match[2]);
                $stats[$status]++;
                if ($status !== 'sent') {
                    $failures[] = $this->failureEvent(
                        $line,
                        $queueId,
                        $status,
                        $senders[$queueId] ?? $this->extractAddress($line, 'from'),
                        $this->extractAddress($line, 'to'),
                        trim($match[3])
                    );
                }
            } elseif (str_contains($line, 'NOQUEUE: reject:')) {
                $stats['rejected']++;
                $reason = trim((string) preg_replace('/^.*NOQUEUE:\s*reject:\s*/', '', $line));
                $failures[] = $this->failureEvent(
                    $line,
                    'NOQUEUE',
                    'rejected',
                    $this->extractAddress($line, 'from'),
                    $this->extractAddress($line, 'to'),
                    $reason
                );
            }

            $spamEvent = $this->parseSpamEvent($line);
            if ($spamEvent !== null) {
                $stats['spam']++;
                $spamEvents[] = $spamEvent;
            }
        }

        return ['stats' => $stats, 'failures' => $failures, 'spam_events' => $spamEvents];
    }

    /** @return array<string, mixed> */
    private function failureEvent(string $line, string $queueId, string $status, string $sender, string $recipient, string $reason): array
    {
        $diagnosis = $this->classifyFailure($reason, $status);
        // An empty envelope sender is Postfix's own bounce notice back to the
        // original sender — failing here means the sending address itself has
        // no mailbox, not that a user mistyped a recipient.
        $isBounceNotice = $sender === '' && $queueId !== 'NOQUEUE';
        if ($isBounceNotice && $diagnosis['category'] === 'mailbox_missing') {
            $diagnosis = array_merge($diagnosis, [
                'category' => 'bounce_notice_undeliverable',
                'label' => 'Bounce notice could not be returned',
                'explanation' => "An earlier message from {$recipient} failed, and the server's failure notice could not be delivered back because {$recipient} has no mailbox.",
                'suggestion' => "Create a mailbox (or forwarder) for {$recipient}, or make the sending app use an address that has one. Then fix the original failure listed next to this event.",
                'severity' => 'medium',
            ]);
        }

        return [
            'id' => sha1($line),
            'timestamp' => $this->timestamp($line),
            'queue_id' => $queueId,
            'status' => $status,
            'sender' => $isBounceNotice ? 'MAILER-DAEMON (bounce notice)' : $sender,
            'is_bounce_notice' => $isBounceNotice,
            'recipient' => $recipient,
            'reason' => $reason,
            'diagnosis' => $diagnosis,
        ];
    }

    /** @return array<string, mixed>|null */
    private function parseSpamEvent(string $line): ?array
    {
        $lower = strtolower($line);
        if (str_contains($lower, 'rspamd') && preg_match('/\baction:\s*([^,;]+)/i', $line, $action)) {
            $actionText = trim($action[1]);
            if (in_array(strtolower($actionText), ['no action', 'greylist'], true)) {
                return null;
            }
            preg_match('/\bscore:\s*([\d.-]+)/i', $line, $score);
            preg_match('/\bqid:\s*<?([A-Z0-9-]+)>?/i', $line, $queueId);

            return [
                'id' => sha1($line),
                'timestamp' => $this->timestamp($line),
                'engine' => 'Rspamd',
                'action' => $actionText,
                'score' => isset($score[1]) ? (float) $score[1] : null,
                'queue_id' => $queueId[1] ?? '',
                'sender' => $this->extractAddress($line, 'from'),
                'recipient' => $this->extractAddress($line, 'to'),
            ];
        }

        if (str_contains($lower, 'spamd: result: y')) {
            preg_match('/spamd:\s*result:\s*Y\s+([\d.-]+)/i', $line, $score);

            return [
                'id' => sha1($line),
                'timestamp' => $this->timestamp($line),
                'engine' => 'SpamAssassin',
                'action' => 'Spam detected',
                'score' => isset($score[1]) ? (float) $score[1] : null,
                'queue_id' => '',
                'sender' => $this->extractAddress($line, 'from'),
                'recipient' => $this->extractAddress($line, 'to'),
            ];
        }

        return null;
    }

    /** @return array{0: array<int, string>, 1: string|null} */
    private function readLogLines(int $maxLines = self::MAX_LOG_LINES): array
    {
        $paths = array_values(array_filter((array) config('serverpanel.mail.health_log_paths', []), 'is_string'));
        foreach ($paths as $path) {
            if (is_file($path) && is_readable($path)) {
                return [array_slice($this->tailLines($path), -$maxLines), $path];
            }
        }

        // The mail log is normally root/adm-only; read it through the root
        // execution API instead of widening the web user's permissions.
        $viaRoot = $this->readLogLinesAsRoot($paths, $maxLines);
        if ($viaRoot !== null) {
            return $viaRoot;
        }

        $journal = @shell_exec('journalctl -u postfix -u rspamd -u spamassassin --no-pager -n '.$maxLines.' 2>/dev/null');
        if (is_string($journal) && trim($journal) !== '' && ! str_contains($journal, 'No journal files were found')) {
            return [array_slice($this->normalizeLines($journal), -$maxLines), 'systemd journal'];
        }

        return [[], null];
    }

    /**
     * @param  array<int, string>  $paths
     * @return array{0: array<int, string>, 1: string}|null
     */
    private function readLogLinesAsRoot(array $paths, int $maxLines = self::MAX_LOG_LINES): ?array
    {
        try {
            $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/mail-log-tail.sh';
            $result = app(ScriptExecutionGateway::class)->execute($script, [(string) $maxLines, ...$paths], [], true);
        } catch (\Throwable) {
            return null;
        }

        if (! $result['success'] || ! preg_match('/^LOG_SOURCE=(.+)$/m', $result['output'], $match)) {
            return null;
        }

        $body = (string) preg_replace('/^LOG_SOURCE=.*\R?/m', '', $result['output'], 1);

        return [array_slice($this->normalizeLines($body), -$maxLines), trim($match[1])];
    }

    /** @return array<int, string> */
    private function tailLines(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return [];
        }
        $size = (int) (@filesize($path) ?: 0);
        if ($size > self::MAX_LOG_BYTES) {
            fseek($handle, -self::MAX_LOG_BYTES, SEEK_END);
            fgets($handle);
        }
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $this->normalizeLines(is_string($contents) ? $contents : '');
    }

    /** @return array<int, string> */
    private function normalizeLines(string $contents): array
    {
        return array_values(array_filter(preg_split('/\R/', $contents) ?: [], static fn ($line) => trim((string) $line) !== ''));
    }

    /** @return array<string, mixed> */
    private function queueSnapshot(): array
    {
        $output = @shell_exec('postqueue -p 2>&1');
        if (! is_string($output) || trim($output) === '' || str_contains(strtolower($output), 'command not found')) {
            return ['available' => false, 'count' => 0, 'size_bytes' => 0, 'messages' => [], 'error' => 'Postfix queue command is unavailable.'];
        }
        if (str_contains(strtolower($output), 'fatal:') || str_contains(strtolower($output), 'permission denied')) {
            return ['available' => false, 'count' => 0, 'size_bytes' => 0, 'messages' => [], 'error' => trim($output)];
        }
        if (str_contains($output, 'Mail queue is empty')) {
            return ['available' => true, 'count' => 0, 'size_bytes' => 0, 'messages' => [], 'error' => null];
        }

        $messages = [];
        $size = 0;
        foreach (preg_split('/\R\R+/', trim($output)) ?: [] as $block) {
            $rows = preg_split('/\R/', trim($block)) ?: [];
            $first = array_shift($rows);
            if (! is_string($first) || ! preg_match('/^([A-F0-9]{5,}[*!]?)\s+(\d+)\s+\S+\s+(.+)$/i', trim($first), $match)) {
                continue;
            }
            $bytes = (int) $match[2];
            $size += $bytes;
            $messages[] = [
                'queue_id' => rtrim($match[1], '*!'),
                'status_marker' => str_ends_with($match[1], '!') ? 'hold' : (str_ends_with($match[1], '*') ? 'active' : 'deferred'),
                'size_bytes' => $bytes,
                'sender' => trim($match[3]),
                'recipients' => array_values(array_filter(array_map('trim', $rows), static fn ($row) => $row !== '' && ! str_starts_with($row, '('))),
            ];
        }

        return ['available' => true, 'count' => count($messages), 'size_bytes' => $size, 'messages' => array_slice($messages, 0, 100), 'error' => null];
    }

    /** @param array<int, string> $lines */
    private function spamEngine(array $lines): array
    {
        $joined = strtolower(implode("\n", array_slice($lines, -1000)));
        if (str_contains($joined, 'rspamd')) {
            return ['name' => 'Rspamd', 'detected' => true];
        }
        if (str_contains($joined, 'spamassassin') || str_contains($joined, 'spamd')) {
            return ['name' => 'SpamAssassin', 'detected' => true];
        }

        return ['name' => null, 'detected' => false];
    }

    /** @param array<string, int> $stats
     *  @param array<string, mixed> $queue
     */
    private function healthScore(array $stats, array $queue, ?string $logSource): ?int
    {
        if ($logSource === null && ! $queue['available']) {
            return null;
        }
        $failures = $stats['bounced'] + $stats['deferred'] + $stats['rejected'];
        $attempts = max(1, $stats['sent'] + $failures);
        $penalty = min(55, (int) round(($failures / $attempts) * 55));
        $penalty += min(25, ((int) $queue['count']) * 2);
        $penalty += min(20, $stats['spam'] * 2);

        return max(0, 100 - $penalty);
    }

    private function extractAddress(string $line, string $field): string
    {
        return preg_match('/\b'.preg_quote($field, '/').'=<([^>]*)>/i', $line, $match) ? trim($match[1]) : '';
    }

    private function timestamp(string $line): string
    {
        // rsyslog on current Ubuntu/Debian writes RFC 3339 ("2026-09-28T19:38:00.123+06:00").
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $line, $match)) {
            return $match[1].' '.$match[2];
        }

        return preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $line, $match)
            ? preg_replace('/\s+/', ' ', trim($match[1]))
            : '';
    }
}
