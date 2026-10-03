<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Models\MailboxMessageMetadata;
use App\Models\MailboxSyncState;
use App\Support\MailHtml;
use App\Support\MailText;
use RuntimeException;

class MailboxImapService
{
    private const MAX_CACHED_BODY_BYTES = 1048576;

    public function __construct(private readonly MailImageProxy $imageProxy) {}

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
     * An already read message whose body is cached needs no IMAP connection.
     *
     * @return array<string, mixed>|null
     */
    public function cachedMessage(Mailbox $mailbox, string $folder, int $uid): ?array
    {
        $metadata = $this->cacheQuery($mailbox, $folder)->where('uid', $uid)->first();
        if ($metadata === null || $metadata->body_text === null || $metadata->body_html === null || ! $metadata->seen) {
            return null;
        }

        return $this->messagePayload($folder, $uid, $metadata, (string) $metadata->body_text, (string) $metadata->body_html);
    }

    /**
     * Load a single message without touching the folder list or message list.
     *
     * @return array<string, mixed>|null
     */
    public function openMessage(Mailbox $mailbox, string $folder, int $uid): ?array
    {
        $cached = $this->cachedMessage($mailbox, $folder, $uid);
        if ($cached !== null) {
            return $cached;
        }

        $stream = $this->open($mailbox, $folder);
        try {
            return $this->message($stream, $mailbox, $folder, $uid);
        } finally {
            $this->close($stream);
        }
    }

    /**
     * @return array{folders: array<int, array{name: string, unread: int, exists: int}>, messages: array<int, array<string, mixed>>, message: array<string, mixed>|null}
     */
    public function loadMailbox(Mailbox $mailbox, string $folder = 'INBOX', ?int $uid = null, int $limit = 40): array
    {
        $stream = $this->open($mailbox, $folder);
        $folders = $this->folders($stream, $mailbox, $folder);
        $messages = $this->messages($stream, $mailbox, $folder, $limit);
        $message = $uid !== null ? $this->message($stream, $mailbox, $folder, $uid) : null;
        $this->close($stream);

        return [
            'folders' => $folders,
            'messages' => $messages,
            'message' => $message,
        ];
    }

    public function deleteMessage(Mailbox $mailbox, string $folder, int $uid): void
    {
        $this->deleteMessages($mailbox, $folder, [$uid]);
    }

    /**
     * @param  array<int, int>  $uids
     */
    public function deleteMessages(Mailbox $mailbox, string $folder, array $uids): void
    {
        $uids = $this->normalizeUids($uids);
        if ($uids === []) {
            return;
        }

        $stream = $this->open($mailbox, $folder);
        foreach (array_chunk($uids, 500) as $chunk) {
            if (! @imap_delete($stream, implode(',', $chunk), FT_UID)) {
                $error = imap_last_error();
                $this->close($stream);
                throw new RuntimeException($error ?: 'Unable to delete messages.');
            }
        }

        @imap_expunge($stream);
        $this->close($stream);
        foreach (array_chunk($uids, 1000) as $chunk) {
            $this->cacheQuery($mailbox, $folder)->whereIn('uid', $chunk)->delete();
        }
    }

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     */
    public function sendMessage(Mailbox $mailbox, array $to, string $subject, string $body, array $cc = [], array $bcc = []): void
    {
        $subject = trim($subject);
        $body = trim($body);

        if ($to === [] || $subject === '' || $body === '') {
            throw new RuntimeException('To, subject and message body are required.');
        }
        foreach (array_merge($to, $cc, $bcc) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Invalid email address: '.$address);
            }
        }

        $from = (string) $mailbox->email;
        $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'From: '.$from,
            'Reply-To: '.$from,
            'Date: '.date(DATE_RFC2822),
            'Message-ID: <'.bin2hex(random_bytes(16)).'@'.$domain.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        if ($cc !== []) {
            $headers[] = 'Cc: '.implode(', ', $cc);
        }

        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : $subject;

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        // sendmail runs with -t, so Bcc recipients are delivered and the header is stripped.
        $sendHeaders = $bcc !== [] ? array_merge($headers, ['Bcc: '.implode(', ', $bcc)]) : $headers;
        $ok = @mail(implode(', ', $to), $encodedSubject, $body, implode("\r\n", $sendHeaders), '-f'.$from);
        if (! $ok) {
            throw new RuntimeException('Message could not be sent.');
        }

        // mail() only hands the message to the local MTA; keep a copy in the
        // mailbox's Sent folder the same way a regular mail client does.
        $raw = implode("\r\n", array_merge(['To: '.implode(', ', $to), 'Subject: '.$encodedSubject], $sendHeaders))
            ."\r\n\r\n".str_replace("\n", "\r\n", $body)."\r\n";
        $this->appendToSent($mailbox, $raw);
    }

    private function appendToSent(Mailbox $mailbox, string $raw): void
    {
        $stream = $this->open($mailbox);
        $prefix = $this->mailboxPrefix();

        $sentFolder = null;
        $mailboxes = @imap_getmailboxes($stream, $prefix, '*');
        foreach (is_array($mailboxes) ? $mailboxes : [] as $entry) {
            $name = $this->stripMailboxPrefix((string) ($entry->name ?? ''));
            if (in_array(strtolower($name), ['sent', 'sent items', 'sent messages', 'inbox.sent'], true)) {
                $sentFolder = $name;
                break;
            }
        }

        if ($sentFolder === null) {
            $sentFolder = 'Sent';
            @imap_createmailbox($stream, imap_utf7_encode($prefix.$sentFolder));
            @imap_subscribe($stream, imap_utf7_encode($prefix.$sentFolder));
        }

        $appended = @imap_append($stream, $prefix.$sentFolder, $raw, '\\Seen');
        $error = imap_last_error();
        $this->close($stream);

        if (! $appended) {
            throw new RuntimeException('Message was sent but could not be saved to Sent: '.($error ?: 'unknown IMAP error'));
        }

        // Force the folder list (and counts) to refresh on the next load.
        MailboxSyncState::query()->where('mailbox_id', $mailbox->id)->update(['folders_synced_at' => null]);
    }

    public function markRead(Mailbox $mailbox, string $folder, int $uid, bool $seen): void
    {
        $this->setSeen($mailbox, $folder, [$uid], $seen);
    }

    /**
     * @param  array<int, int>  $uids
     */
    public function setSeen(Mailbox $mailbox, string $folder, array $uids, bool $seen): void
    {
        $uids = $this->normalizeUids($uids);
        if ($uids === []) {
            return;
        }

        $stream = $this->open($mailbox, $folder);
        foreach (array_chunk($uids, 500) as $chunk) {
            // "Unread" means clearing \Seen; there is no \Unseen flag in IMAP.
            $result = $seen
                ? @imap_setflag_full($stream, implode(',', $chunk), '\\Seen', ST_UID)
                : @imap_clearflag_full($stream, implode(',', $chunk), '\\Seen', ST_UID);
            if (! $result) {
                $error = imap_last_error();
                $this->close($stream);
                throw new RuntimeException($error ?: 'Unable to update message flags.');
            }
        }
        $this->close($stream);

        foreach (array_chunk($uids, 1000) as $chunk) {
            $this->cacheQuery($mailbox, $folder)->whereIn('uid', $chunk)->update(['seen' => $seen, 'synced_at' => now()]);
        }
    }

    /**
     * @param  array<int, mixed>  $uids
     * @return array<int, int>
     */
    private function normalizeUids(array $uids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $uids), fn (int $uid): bool => $uid > 0)));
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
     * Incremental sync in the style of Roundcube's message index: a single
     * STATUS call is compared with the stored folder signature, and IMAP is
     * only asked for what changed (new UIDs, removed UIDs, unseen flags).
     *
     * @param  resource  $stream
     * @return array<int, array<string, mixed>>
     */
    private function messages($stream, Mailbox $mailbox, string $folder, int $limit): array
    {
        $status = @imap_status($stream, $this->mailboxPath($folder), SA_ALL);
        if (! is_object($status)) {
            throw new RuntimeException(imap_last_error() ?: 'Unable to read folder status.');
        }

        $uidValidity = (int) ($status->uidvalidity ?? 0);
        $count = (int) ($status->messages ?? 0);
        $uidNext = (int) ($status->uidnext ?? 0);
        $unseen = (int) ($status->unseen ?? 0);

        $state = MailboxSyncState::query()->firstOrCreate(['mailbox_id' => $mailbox->id, 'folder' => $folder]);
        if ($uidValidity > 0 && $state->uid_validity && (int) $state->uid_validity !== $uidValidity) {
            $this->cacheQuery($mailbox, $folder)->delete();
            $state->message_count = null;
        }

        $unchanged = $state->message_count !== null
            && (int) $state->message_count === $count
            && (int) $state->uid_next === $uidNext
            && (int) $state->unseen_count === $unseen
            && (int) $state->uid_validity === $uidValidity;

        if (! $unchanged) {
            $this->syncFolder($stream, $mailbox, $folder, $count, $unseen);
            $state->forceFill([
                'uid_validity' => $uidValidity ?: $state->uid_validity,
                'uid_next' => $uidNext,
                'message_count' => $count,
                'unseen_count' => $unseen,
                'messages_synced_at' => now(),
            ])->save();
        }

        $rows = $this->cacheQuery($mailbox, $folder)->orderByDesc('uid')->limit(max(1, $limit))->get();

        // Snippets need a body fetch, so they are only built for the visible page.
        foreach ($rows as $row) {
            if ($row->snippet === null) {
                $row->forceFill(['snippet' => $this->snippet($stream, (int) $row->uid)])->save();
            }
        }

        return $rows->map(fn (MailboxMessageMetadata $metadata): array => $this->metadataRow($metadata))->all();
    }

    /**
     * @param  resource  $stream
     */
    private function syncFolder($stream, Mailbox $mailbox, string $folder, int $count, int $unseen): void
    {
        if ($count === 0) {
            $this->cacheQuery($mailbox, $folder)->delete();

            return;
        }

        // New mail: one FETCH for every UID above the highest cached one.
        $maxUid = (int) $this->cacheQuery($mailbox, $folder)->max('uid');
        $this->storeOverviews($mailbox, $folder, @imap_fetch_overview($stream, ($maxUid + 1).':*', FT_UID), $maxUid);

        // Expunged or not-yet-cached messages: only then is the full UID list needed.
        if ($this->cacheQuery($mailbox, $folder)->count() !== $count) {
            $serverUids = array_map('intval', @imap_search($stream, 'ALL', SE_UID) ?: []);
            $cachedUids = $this->cacheQuery($mailbox, $folder)->pluck('uid')->map(fn ($uid): int => (int) $uid)->all();

            foreach (array_chunk(array_values(array_diff($cachedUids, $serverUids)), 1000) as $chunk) {
                $this->cacheQuery($mailbox, $folder)->whereIn('uid', $chunk)->delete();
            }
            foreach (array_chunk(array_values(array_diff($serverUids, $cachedUids)), 500) as $chunk) {
                $this->storeOverviews($mailbox, $folder, @imap_fetch_overview($stream, implode(',', $chunk), FT_UID));
            }
        }

        // Flags changed elsewhere (another client, phone): resync unseen state in one SEARCH.
        if ($this->cacheQuery($mailbox, $folder)->where('seen', false)->count() !== $unseen) {
            $unseenUids = array_map('intval', @imap_search($stream, 'UNSEEN', SE_UID) ?: []);
            $this->cacheQuery($mailbox, $folder)->where('seen', false)->update(['seen' => true]);
            foreach (array_chunk($unseenUids, 1000) as $chunk) {
                $this->cacheQuery($mailbox, $folder)->whereIn('uid', $chunk)->update(['seen' => false]);
            }
        }
    }

    /**
     * @param  array<int, object>|false  $overviews
     */
    private function storeOverviews(Mailbox $mailbox, string $folder, array|false $overviews, int $afterUid = 0): void
    {
        $now = now();
        $rows = [];
        foreach (is_array($overviews) ? $overviews : [] as $overview) {
            $uid = (int) ($overview->uid ?? 0);
            // "n:*" always returns the last message, even when it is older than n.
            if ($uid <= $afterUid) {
                continue;
            }

            $rows[] = [
                'mailbox_id' => $mailbox->id,
                'folder' => $folder,
                'uid' => $uid,
                'subject' => $this->decodeHeader((string) ($overview->subject ?? '(no subject)')),
                'sender' => $this->decodeHeader((string) ($overview->from ?? '')),
                'recipient' => $this->decodeHeader((string) ($overview->to ?? '')),
                'message_date' => MailText::toUtf8((string) ($overview->date ?? '')),
                'seen' => (bool) ($overview->seen ?? false),
                'size' => (int) ($overview->size ?? 0),
                'snippet' => null,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MailboxMessageMetadata::query()->upsert(
                $chunk,
                ['mailbox_id', 'folder', 'uid'],
                ['subject', 'sender', 'recipient', 'message_date', 'seen', 'size', 'synced_at', 'updated_at']
            );
        }
    }

    private function cacheQuery(Mailbox $mailbox, string $folder): \Illuminate\Database\Eloquent\Builder
    {
        return MailboxMessageMetadata::query()->where('mailbox_id', $mailbox->id)->where('folder', $folder);
    }

    /** @return array<string, mixed> */
    private function metadataRow(MailboxMessageMetadata $metadata): array
    {
        return [
            'uid' => $metadata->uid,
            'subject' => MailText::toUtf8((string) ($metadata->subject ?: '(no subject)')),
            'from' => MailText::toUtf8((string) $metadata->sender),
            'to' => MailText::toUtf8((string) $metadata->recipient),
            'date' => MailText::toUtf8((string) $metadata->message_date),
            'seen' => $metadata->seen,
            'size' => $metadata->size,
            'snippet' => MailText::toUtf8((string) $metadata->snippet),
        ];
    }

    /**
     * Opened messages are served from the body cache after the first read.
     *
     * @param  resource  $stream
     * @return array<string, mixed>|null
     */
    private function message($stream, Mailbox $mailbox, string $folder, int $uid): ?array
    {
        $metadata = $this->cacheQuery($mailbox, $folder)->where('uid', $uid)->first();

        if ($metadata === null || $metadata->body_text === null || $metadata->body_html === null) {
            $overviewList = @imap_fetch_overview($stream, (string) $uid, FT_UID);
            $overview = is_array($overviewList) && isset($overviewList[0]) ? $overviewList[0] : null;
            if (! is_object($overview)) {
                return null;
            }

            $this->storeOverviews($mailbox, $folder, [$overview]);
            $metadata = $this->cacheQuery($mailbox, $folder)->where('uid', $uid)->first();
            ['text' => $text, 'html' => $html] = $this->extractBodies($stream, $uid);
            if ($metadata !== null && strlen($text) + strlen($html) <= self::MAX_CACHED_BODY_BYTES) {
                $metadata->forceFill(['body_text' => $text, 'body_html' => $html])->save();
            }
        } else {
            $text = (string) $metadata->body_text;
            $html = (string) $metadata->body_html;
        }

        if ($metadata !== null && ! $metadata->seen && @imap_setflag_full($stream, (string) $uid, '\\Seen', ST_UID)) {
            $metadata->forceFill(['seen' => true])->save();
        }

        return $this->messagePayload($folder, $uid, $metadata, $text, $html);
    }

    /** @return array<string, mixed> */
    private function messagePayload(string $folder, int $uid, ?MailboxMessageMetadata $metadata, string $text, string $html = ''): array
    {
        return [
            'uid' => $uid,
            'folder' => $folder,
            'subject' => MailText::toUtf8((string) ($metadata?->subject ?: '(no subject)')),
            'from' => MailText::toUtf8((string) $metadata?->sender),
            'to' => MailText::toUtf8((string) $metadata?->recipient),
            'date' => MailText::toUtf8((string) $metadata?->message_date),
            'seen' => (bool) $metadata?->seen,
            'raw_header' => '',
            'raw_body' => '',
            'text' => MailText::toUtf8($text),
            'html' => $this->imageProxy->rewrite(MailText::toUtf8($html)),
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
        return $this->extractBodies($stream, $uid, false)['text'];
    }

    /**
     * Plain text for previews, replies and forwards, plus the sanitized HTML
     * part (inline cid: images embedded) so the message keeps its layout.
     *
     * @param  resource  $stream
     * @return array{text: string, html: string}
     */
    private function extractBodies($stream, int $uid, bool $withHtml = true): array
    {
        $structure = @imap_fetchstructure($stream, $uid, FT_UID);
        if (! is_object($structure)) {
            return ['text' => '', 'html' => ''];
        }

        $parts = $this->findBodyParts($structure);
        $fetch = function (string $mime) use ($stream, $uid, $parts): ?string {
            if (! isset($parts[$mime])) {
                return null;
            }

            $part = $parts[$mime];
            $body = (string) @imap_fetchbody($stream, $uid, $part['part'], FT_UID | FT_PEEK);
            $body = $this->decodePart($body, (int) ($part['encoding'] ?? 0));

            return $mime === 'text/html'
                ? MailText::toUtf8($body, $part['charset'] ?? MailText::htmlMetaCharset($body))
                : trim(MailText::toUtf8($body, $part['charset'] ?? null));
        };

        $plain = $fetch('text/plain');
        $html = ($withHtml || $plain === null) ? $fetch('text/html') : null;

        if ($plain === null && $html === null) {
            $body = (string) @imap_body($stream, $uid, FT_UID | FT_PEEK);

            return ['text' => trim(MailText::toUtf8($body)), 'html' => ''];
        }

        $text = $plain ?? MailText::htmlToText((string) $html);
        if (! $withHtml || $html === null) {
            return ['text' => $text, 'html' => ''];
        }

        $html = $this->embedInlineImages($stream, $uid, $structure, $html);

        return ['text' => $text, 'html' => MailHtml::sanitize($html)];
    }

    /**
     * Replace cid: references with data URIs of the matching MIME parts.
     *
     * @param  resource  $stream
     */
    private function embedInlineImages($stream, int $uid, object $structure, string $html): string
    {
        if (stripos($html, 'cid:') === false) {
            return $html;
        }

        $budget = self::MAX_CACHED_BODY_BYTES - strlen($html);
        foreach ($this->findContentIdParts($structure) as $contentId => $part) {
            $pattern = '/cid:'.preg_quote($contentId, '/').'(?=["\'\s)>])/i';
            if (! preg_match($pattern, $html)) {
                continue;
            }

            $data = $this->decodePart((string) @imap_fetchbody($stream, $uid, $part['part'], FT_UID | FT_PEEK), $part['encoding']);
            $uri = 'data:'.$part['mime'].';base64,'.base64_encode($data);
            if (strlen($uri) > $budget) {
                continue;
            }

            $budget -= strlen($uri);
            $html = preg_replace($pattern, $uri, $html) ?? $html;
        }

        return $html;
    }

    /**
     * @return array<string, array{part: string, encoding: int, mime: string}>
     */
    private function findContentIdParts(object $structure, string $prefix = ''): array
    {
        $found = [];
        $mime = $this->mimeType($structure);
        if (str_starts_with($mime, 'image/') && ! empty($structure->ifid)) {
            $contentId = trim((string) ($structure->id ?? ''), " <>\t");
            if ($contentId !== '') {
                $found[$contentId] = [
                    'part' => $prefix === '' ? '1' : $prefix,
                    'encoding' => (int) ($structure->encoding ?? 0),
                    'mime' => $mime,
                ];
            }
        }

        foreach ((array) ($structure->parts ?? []) as $index => $part) {
            if (is_object($part)) {
                $found += $this->findContentIdParts($part, $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1));
            }
        }

        return $found;
    }

    /**
     * @return array<string, array{part: string, encoding: int, charset: string|null}>
     */
    private function findBodyParts(object $structure, string $prefix = ''): array
    {
        $parts = [];
        $mime = $this->mimeType($structure);
        $partNumber = $prefix === '' ? '1' : $prefix;

        $isAttachment = ! empty($structure->ifdisposition)
            && strtolower((string) ($structure->disposition ?? '')) === 'attachment';
        if (($mime === 'text/plain' || $mime === 'text/html') && ! $isAttachment) {
            $parts[$mime] = [
                'part' => $partNumber,
                'encoding' => (int) ($structure->encoding ?? 0),
                'charset' => $this->partCharset($structure),
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

    private function partCharset(object $structure): ?string
    {
        foreach ((array) ($structure->parameters ?? []) as $parameter) {
            if (is_object($parameter) && strtolower((string) ($parameter->attribute ?? '')) === 'charset') {
                return (string) ($parameter->value ?? '') ?: null;
            }
        }

        return null;
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
            3 => base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? $body) ?: '',
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
            return trim(MailText::toUtf8($value));
        }

        $text = '';
        foreach ($decoded as $part) {
            $text .= MailText::toUtf8((string) ($part->text ?? ''), (string) ($part->charset ?? ''));
        }

        return trim($text !== '' ? $text : MailText::toUtf8($value));
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
