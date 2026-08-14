<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Models\MailboxMessageMetadata;
use App\Models\MailboxSyncState;
use RuntimeException;

class MailboxImapService
{
    /**
     * Return the last database snapshot without opening an IMAP connection.
     *
     * @return array{folders: array<int, array<string, mixed>>, messages: array<int, array<string, mixed>>, message: null}|null
     */
    public function cachedMailbox(Mailbox $mailbox, string $folder = 'INBOX', int $limit = 40): ?array
    {
        $state = MailboxSyncState::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('folder', $folder)
            ->whereNotNull('folders_synced_at')
            ->first();
        if (! $state || ! is_array($state->folders)) {
            return null;
        }

        $messages = MailboxMessageMetadata::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('folder', $folder)
            ->orderByDesc('uid')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (MailboxMessageMetadata $metadata): array => $this->metadataRow($metadata))
            ->all();

        return ['folders' => $state->folders, 'messages' => $messages, 'message' => null];
    }

    /**
     * @return array{folders: array<int, array{name: string, unread: int, exists: int}>, messages: array<int, array<string, mixed>>, message: array<string, mixed>|null}
     */
    public function loadMailbox(Mailbox $mailbox, string $folder = 'INBOX', ?int $uid = null, int $limit = 40): array
    {
        $stream = $this->open($mailbox, $folder);
        $folders = $this->folders($stream, $mailbox, $folder);
        $messages = $this->messages($stream, $mailbox, $folder, $limit);
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
        MailboxMessageMetadata::query()->where('mailbox_id', $mailbox->id)
            ->where('folder', $folder)->where('uid', $uid)->delete();
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

        MailboxMessageMetadata::query()->where('mailbox_id', $mailbox->id)
            ->where('folder', $folder)->where('uid', $uid)
            ->update(['seen' => $seen, 'synced_at' => now()]);
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
        $clientPassword = (string) ($mailbox->client_password ?? '');
        if ($clientPassword === '') {
            throw new RuntimeException('Mailbox password must be reset before the webmail client can connect.');
        }
        $stream = @imap_open($mailboxPath, (string) $mailbox->email, $clientPassword, 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if ($stream === false) {
            throw new RuntimeException(imap_last_error() ?: 'IMAP login failed.');
        }

        return $stream;
    }

    /**
     * @param  resource  $stream
     * @return array<int, array{name: string, unread: int, exists: int}>
     */
    private function folders($stream, Mailbox $mailbox, string $selectedFolder): array
    {
        $state = MailboxSyncState::query()->firstOrCreate(['mailbox_id' => $mailbox->id, 'folder' => $selectedFolder]);
        if ($state->folders_synced_at?->isAfter(now()->subSeconds(60)) && is_array($state->folders) && $state->folders !== []) {
            return $state->folders;
        }

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

        $state->forceFill(['folders' => $folders, 'folders_synced_at' => now()])->save();

        return $folders;
    }

    /**
     * @param  resource  $stream
     * @return array<int, array<string, mixed>>
     */
    private function messages($stream, Mailbox $mailbox, string $folder, int $limit): array
    {
        $this->guardUidValidity($stream, $mailbox, $folder);
        $uids = @imap_search($stream, 'ALL', SE_UID);
        if (! is_array($uids) || $uids === []) {
            MailboxMessageMetadata::query()->where('mailbox_id', $mailbox->id)
                ->where('folder', $folder)->delete();

            return [];
        }

        rsort($uids);
        $uids = array_slice($uids, 0, max(1, $limit));

        $cached = MailboxMessageMetadata::query()->where('mailbox_id', $mailbox->id)
            ->where('folder', $folder)->whereIn('uid', $uids)->get()->keyBy('uid');
        $messages = [];
        $freshAfter = now()->subSeconds(60);
        foreach ($uids as $uid) {
            $metadata = $cached->get((int) $uid);
            if ($metadata && $metadata->synced_at?->isAfter($freshAfter)) {
                $messages[] = $this->metadataRow($metadata);

                continue;
            }

            $overviewList = @imap_fetch_overview($stream, (string) $uid, FT_UID);
            $overview = is_array($overviewList) && isset($overviewList[0]) ? $overviewList[0] : null;
            if (! is_object($overview)) {
                continue;
            }

            $row = [
                'uid' => (int) $uid,
                'subject' => $this->decodeHeader((string) ($overview->subject ?? '(no subject)')),
                'from' => $this->decodeHeader((string) ($overview->from ?? '')),
                'date' => (string) ($overview->date ?? ''),
                'seen' => (bool) ($overview->seen ?? false),
                'size' => (int) ($overview->size ?? 0),
                'snippet' => $this->snippet($stream, (int) $uid),
            ];
            MailboxMessageMetadata::query()->updateOrCreate(
                ['mailbox_id' => $mailbox->id, 'folder' => $folder, 'uid' => (int) $uid],
                ['subject' => $row['subject'], 'sender' => $row['from'], 'message_date' => $row['date'], 'seen' => $row['seen'], 'size' => $row['size'], 'snippet' => $row['snippet'], 'synced_at' => now()]
            );
            $messages[] = $row;
        }

        MailboxMessageMetadata::query()->where('mailbox_id', $mailbox->id)
            ->where('folder', $folder)->whereNotIn('uid', $uids)->delete();

        return $messages;
    }

    /** @return array<string, mixed> */
    private function metadataRow(MailboxMessageMetadata $metadata): array
    {
        return [
            'uid' => $metadata->uid,
            'subject' => (string) ($metadata->subject ?: '(no subject)'),
            'from' => (string) $metadata->sender,
            'date' => (string) $metadata->message_date,
            'seen' => $metadata->seen,
            'size' => $metadata->size,
            'snippet' => (string) $metadata->snippet,
        ];
    }

    /** @param resource $stream */
    private function guardUidValidity($stream, Mailbox $mailbox, string $folder): void
    {
        $status = @imap_status($stream, $this->mailboxPath($folder), SA_UIDVALIDITY);
        $uidValidity = is_object($status) ? (int) ($status->uidvalidity ?? 0) : 0;
        $state = MailboxSyncState::query()->firstOrCreate(['mailbox_id' => $mailbox->id, 'folder' => $folder]);
        if ($uidValidity > 0 && $state->uid_validity && (int) $state->uid_validity !== $uidValidity) {
            MailboxMessageMetadata::query()->where('mailbox_id', $mailbox->id)->where('folder', $folder)->delete();
        }
        if ($uidValidity > 0 && (int) $state->uid_validity !== $uidValidity) {
            $state->forceFill(['uid_validity' => $uidValidity])->save();
        }
    }

    /**
     * @param  resource  $stream
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
     * @param  resource  $stream
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
     * @param  resource  $stream
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
     * @param  resource  $stream
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
        $configured = trim((string) config('app.roundcube_imap_host', 'imaps://127.0.0.1:993'));
        $parts = parse_url($configured) ?: [];
        $host = (string) ($parts['host'] ?? '127.0.0.1');
        $port = (int) ($parts['port'] ?? 993);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'tls'));
        $flags = match ($scheme) {
            'ssl', 'imaps' => '/imap/ssl',
            'tls' => '/imap/tls',
            default => '/imap',
        };
        // The panel connects locally to Dovecot. Its default certificate is
        // self-signed, so validate it only after a real certificate is set.
        if (in_array($host, ['127.0.0.1', '::1', 'localhost'], true) && in_array($scheme, ['ssl', 'imaps'], true)) {
            $flags .= '/novalidate-cert';
        }

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
