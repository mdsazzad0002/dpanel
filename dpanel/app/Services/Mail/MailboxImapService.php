<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use RuntimeException;

class MailboxImapService
{
    /**
     * @return array{folders: array<int, array{name: string, unread: int, exists: int}>, messages: array<int, array<string, mixed>>, message: array<string, mixed>|null}
     */
    public function loadMailbox(Mailbox $mailbox, string $folder = 'INBOX', ?int $uid = null, int $limit = 40): array
    {
        $stream = $this->open($mailbox, $folder);
        $folders = $this->folders($stream);
        $messages = $this->messages($stream, $folder, $limit);
        $message = $uid !== null ? $this->message($stream, $folder, $uid) : null;
        $this->close($stream);

        return [
            'folders' => $folders,
            'messages' => $messages,
            'message' => $message,
        ];
    }

    public function deleteMessage(Mailbox $mailbox, string $folder, int $uid): void
    {
        $stream = $this->open($mailbox, $folder);
        if (! @imap_delete($stream, (string) $uid, FT_UID)) {
            $this->close($stream);
            throw new RuntimeException(imap_last_error() ?: 'Unable to delete message.');
        }

        @imap_expunge($stream);
        $this->close($stream);
    }

    public function sendMessage(Mailbox $mailbox, string $to, string $subject, string $body): void
    {
        $to = trim($to);
        $subject = trim($subject);
        $body = trim($body);

        if ($to === '' || $subject === '' || $body === '') {
            throw new RuntimeException('To, subject and message body are required.');
        }

        $from = (string) $mailbox->email;
        $headers = [
            'From: '.$from,
            'Reply-To: '.$from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : $subject;

        $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f'.$from);
        if (! $ok) {
            throw new RuntimeException('Message could not be sent.');
        }
    }

    public function markRead(Mailbox $mailbox, string $folder, int $uid, bool $seen): void
    {
        $stream = $this->open($mailbox, $folder);
        $flags = $seen ? '\\Seen' : '\\Unseen';
        $result = @imap_setflag_full($stream, (string) $uid, $flags, FT_UID);
        $this->close($stream);

        if (! $result) {
            throw new RuntimeException(imap_last_error() ?: 'Unable to update message flag.');
        }
    }

    /**
     * @return resource
     */
    private function open(Mailbox $mailbox, string $folder = 'INBOX')
    {
        if (! function_exists('imap_open')) {
            throw new RuntimeException('PHP imap extension is missing.');
        }

        $mailboxPath = $this->mailboxPath($folder);
        $stream = @imap_open($mailboxPath, (string) $mailbox->email, (string) $mailbox->password, 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if ($stream === false) {
            throw new RuntimeException(imap_last_error() ?: 'IMAP login failed.');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     * @return array<int, array{name: string, unread: int, exists: int}>
     */
    private function folders($stream): array
    {
        $prefix = $this->mailboxPrefix();
        $mailboxes = @imap_getmailboxes($stream, $prefix, '*');
        if (! is_array($mailboxes)) {
            return [['name' => 'INBOX', 'unread' => 0, 'exists' => 0]];
        }

        $folders = [];
        foreach ($mailboxes as $mailbox) {
            $fullName = (string) ($mailbox->name ?? '');
            $folder = $this->stripMailboxPrefix($fullName);
            if ($folder === '') {
                continue;
            }

            $status = @imap_status($stream, $prefix.$folder, SA_MESSAGES | SA_UNSEEN);
            $folders[] = [
                'name' => $folder,
                'unread' => is_object($status) ? (int) ($status->unseen ?? 0) : 0,
                'exists' => is_object($status) ? (int) ($status->messages ?? 0) : 0,
            ];
        }

        $hasInbox = false;
        foreach ($folders as $folder) {
            if (strcasecmp((string) ($folder['name'] ?? ''), 'INBOX') === 0) {
                $hasInbox = true;
                break;
            }
        }

        if (! $hasInbox) {
            $inboxStatus = @imap_status($stream, $prefix.'INBOX', SA_MESSAGES | SA_UNSEEN);
            array_unshift($folders, [
                'name' => 'INBOX',
                'unread' => is_object($inboxStatus) ? (int) ($inboxStatus->unseen ?? 0) : 0,
                'exists' => is_object($inboxStatus) ? (int) ($inboxStatus->messages ?? 0) : 0,
            ]);
        }

        usort($folders, static function (array $left, array $right): int {
            if (strcasecmp((string) ($left['name'] ?? ''), 'INBOX') === 0) {
                return -1;
            }

            if (strcasecmp((string) ($right['name'] ?? ''), 'INBOX') === 0) {
                return 1;
            }

            return strcasecmp($left['name'], $right['name']);
        });

        return $folders;
    }

    /**
     * @param resource $stream
     * @return array<int, array<string, mixed>>
     */
    private function messages($stream, string $folder, int $limit): array
    {
        $uids = @imap_search($stream, 'ALL', SE_UID);
        if (! is_array($uids) || $uids === []) {
            return [];
        }

        rsort($uids);
        $uids = array_slice($uids, 0, max(1, $limit));

        $messages = [];
        foreach ($uids as $uid) {
            $overviewList = @imap_fetch_overview($stream, (string) $uid, FT_UID);
            $overview = is_array($overviewList) && isset($overviewList[0]) ? $overviewList[0] : null;
            if (! is_object($overview)) {
                continue;
            }

            $messages[] = [
                'uid' => (int) $uid,
                'subject' => $this->decodeHeader((string) ($overview->subject ?? '(no subject)')),
                'from' => $this->decodeHeader((string) ($overview->from ?? '')),
                'date' => (string) ($overview->date ?? ''),
                'seen' => (bool) ($overview->seen ?? false),
                'size' => (int) ($overview->size ?? 0),
                'snippet' => $this->snippet($stream, (int) $uid),
            ];
        }

        return $messages;
    }

    /**
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    private function message($stream, string $folder, int $uid): ?array
    {
        $overviewList = @imap_fetch_overview($stream, (string) $uid, FT_UID);
        $overview = is_array($overviewList) && isset($overviewList[0]) ? $overviewList[0] : null;
        if (! is_object($overview)) {
            return null;
        }

        $rawHeader = @imap_fetchheader($stream, $uid, FT_UID) ?: '';
        $rawBody = @imap_body($stream, $uid, FT_UID) ?: '';
        $text = $this->extractText($stream, $uid);

        return [
            'uid' => $uid,
            'folder' => $folder,
            'subject' => $this->decodeHeader((string) ($overview->subject ?? '(no subject)')),
            'from' => $this->decodeHeader((string) ($overview->from ?? '')),
            'to' => $this->decodeHeader((string) ($overview->to ?? '')),
            'date' => (string) ($overview->date ?? ''),
            'raw_header' => $rawHeader,
            'raw_body' => $rawBody,
            'text' => $text,
        ];
    }

    /**
     * @param resource $stream
     */
    private function snippet($stream, int $uid): string
    {
        $text = trim($this->extractText($stream, $uid));
        if ($text === '') {
            return '';
        }

        return mb_substr(preg_replace('/\s+/', ' ', $text) ?: $text, 0, 160);
    }

    /**
     * @param resource $stream
     */
    private function extractText($stream, int $uid): string
    {
        $structure = @imap_fetchstructure($stream, $uid, FT_UID);
        if (! is_object($structure)) {
            return '';
        }

        $parts = $this->findBodyParts($structure);
        foreach (['text/plain', 'text/html'] as $mime) {
            if (isset($parts[$mime])) {
                $part = $parts[$mime];
                $body = (string) @imap_fetchbody($stream, $uid, $part['part'], FT_UID);
                $body = $this->decodePart($body, (int) ($part['encoding'] ?? 0));
                if ($mime === 'text/html') {
                    $body = strip_tags(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }

                return trim($body);
            }
        }

        $body = (string) @imap_body($stream, $uid, FT_UID);

        return trim($body);
    }

    /**
     * @return array<string, array{part: string, encoding: int}>
     */
    private function findBodyParts(object $structure, string $prefix = ''): array
    {
        $parts = [];
        $mime = $this->mimeType($structure);
        $partNumber = $prefix === '' ? '1' : $prefix;

        if ($mime === 'text/plain' || $mime === 'text/html') {
            $parts[$mime] = [
                'part' => $partNumber,
                'encoding' => (int) ($structure->encoding ?? 0),
            ];
        }

        if (! empty($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                if (! is_object($part)) {
                    continue;
                }

                $childPrefix = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
                $parts = $parts + $this->findBodyParts($part, $childPrefix);
            }
        }

        return $parts;
    }

    private function mimeType(object $structure): string
    {
        $primary = (int) ($structure->type ?? 0);
        $subtype = strtolower((string) ($structure->subtype ?? ''));

        return match ($primary) {
            0 => 'text/'.($subtype !== '' ? $subtype : 'plain'),
            1 => 'multipart/'.($subtype !== '' ? $subtype : 'mixed'),
            2 => 'message/'.($subtype !== '' ? $subtype : 'rfc822'),
            3 => 'application/'.($subtype !== '' ? $subtype : 'octet-stream'),
            4 => 'audio/'.($subtype !== '' ? $subtype : 'basic'),
            5 => 'image/'.($subtype !== '' ? $subtype : 'jpeg'),
            6 => 'video/'.($subtype !== '' ? $subtype : 'mpeg'),
            7 => 'application/'.($subtype !== '' ? $subtype : 'octet-stream'),
            default => 'application/octet-stream',
        };
    }

    private function decodePart(string $body, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($body, true) ?: '',
            4 => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $decoded = @imap_mime_header_decode($value);
        if (! is_array($decoded) || $decoded === []) {
            return trim($value);
        }

        $text = '';
        foreach ($decoded as $part) {
            $text .= (string) ($part->text ?? '');
        }

        return trim($text !== '' ? $text : $value);
    }

    /**
     * @param resource $stream
     */
    private function close($stream): void
    {
        if (is_resource($stream) || $stream instanceof \IMAP\Connection) {
            @imap_close($stream);
        }
    }

    private function mailboxPrefix(): string
    {
        return $this->mailboxPath('');
    }

    private function mailboxPath(string $folder): string
    {
        $configured = trim((string) config('app.roundcube_imap_host', 'tls://127.0.0.1'));
        $parts = parse_url($configured) ?: [];
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 993);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'tls'));
        $flags = match ($scheme) {
            'ssl', 'imaps' => '/imap/ssl',
            'tls' => '/imap/tls',
            default => '/imap',
        };

        $folder = ltrim($folder, '/');

        return sprintf('{%s:%d%s}%s', $host, $port, $flags, $folder);
    }

    private function stripMailboxPrefix(string $mailbox): string
    {
        if (preg_match('/^\{[^}]+\}(.*)$/', $mailbox, $matches)) {
            return (string) ($matches[1] ?? '');
        }

        return $mailbox;
    }
}
