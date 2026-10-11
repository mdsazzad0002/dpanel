<?php

namespace App\Services\Mail;

use App\Models\Mailbox;
use App\Models\MailboxMessageMetadata;
use App\Models\MailboxSyncState;
use App\Support\MailHtml;
use App\Support\MailText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MailboxImapService
{
    private const MAX_CACHED_BODY_BYTES = 1048576;

    /** Thrown (as the exception code) when the requested folder does not exist. */
    public const FOLDER_MISSING = 404;

    /** Standard folders and the names that already count as them. */
    private const DEFAULT_FOLDERS = [
        'Sent' => '/sent/i',
        'Drafts' => '/draft/i',
        'Junk' => '/junk|spam/i',
        'Trash' => '/trash|deleted/i',
    ];

    private readonly MailMessageAnalyzer $analyzer;

    public function __construct(private readonly MailImageProxy $imageProxy, ?MailMessageAnalyzer $analyzer = null)
    {
        $this->analyzer = $analyzer ?? new MailMessageAnalyzer;
    }

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
        // Bodies cached before sender verification existed go through IMAP once.
        if ($metadata === null || $metadata->body_text === null || $metadata->body_html === null || ! $metadata->seen || $metadata->sender_verified === null) {
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
     * The message exactly as stored, like Gmail's "Show original". Fetched on
     * demand and never cached. The server writes it to a disk-backed stream,
     * so a large attachment never sits in memory; only the first $maxBytes
     * are returned for display.
     *
     * @return array{headers: string, raw: string, size: int, truncated: bool}|null
     */
    public function rawMessage(Mailbox $mailbox, string $folder, int $uid, int $maxBytes = 1048576): ?array
    {
        $file = fopen('php://temp/maxmemory:1048576', 'w+b');
        try {
            $headers = $this->saveRawMessage($mailbox, $folder, $uid, $file);
            if ($headers === null) {
                return null;
            }

            $size = (int) ftell($file);
            rewind($file);

            return [
                'headers' => $headers,
                'raw' => (string) stream_get_contents($file, $maxBytes),
                'size' => $size,
                'truncated' => $size > $maxBytes,
            ];
        } finally {
            fclose($file);
        }
    }

    /**
     * Write the full RFC 822 message (the .eml) to $target.
     *
     * @param  resource  $target
     * @return string|null the header block, or null when the message does not exist
     */
    public function saveRawMessage(Mailbox $mailbox, string $folder, int $uid, $target): ?string
    {
        $stream = $this->open($mailbox, $folder);
        try {
            $headers = @imap_fetchheader($stream, $uid, FT_UID);
            if (! is_string($headers) || trim($headers) === '') {
                return null;
            }
            // Section "" is the whole message; FT_PEEK leaves it unread.
            if (! @imap_savebody($stream, $target, $uid, '', FT_UID | FT_PEEK)) {
                throw new RuntimeException(imap_last_error() ?: 'Unable to read the message source.');
            }

            return $headers;
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
     * @param  array{message_id?: string, references?: string}  $thread  headers of the message being replied to
     * @return array{held: array<int, string>} held: external recipients a sandbox mailbox did not send to
     */
    public function sendMessage(Mailbox $mailbox, array $to, string $subject, string $body, array $cc = [], array $bcc = [], string $html = '', array $thread = []): array
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
        ];
        // Threading: clients group a reply with its original by these.
        $parentId = trim((string) ($thread['message_id'] ?? ''));
        if ($parentId !== '') {
            $headers[] = 'In-Reply-To: '.$parentId;
            $headers[] = 'References: '.trim(trim((string) ($thread['references'] ?? '')).' '.$parentId);
        }

        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : $subject;

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $html = MailHtml::sanitize($html);
        if ($html === '') {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
        } else {
            // The editor's formatting as HTML, with the plain text for clients that want it.
            $boundary = 'dpanel-'.bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="'.$boundary.'"';
            $part = fn (string $type, string $content): string => "--{$boundary}\nContent-Type: {$type}; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n"
                .rtrim(chunk_split(base64_encode($content), 76, "\n"))."\n";
            $body = $part('text/plain', $body)
                .$part('text/html', '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>')
                ."--{$boundary}--";
        }

        // Sandbox (test) mailboxes only reach mailboxes hosted here. Postfix
        // enforces the same rule for SMTP clients; filtering here as well
        // lets the panel say which recipients were held back.
        $send = ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];
        $held = [];
        if ($mailbox->isSandbox()) {
            $local = $this->hostedRecipients(array_merge($to, $cc, $bcc));
            $send = array_map(fn (array $list): array => array_values(array_intersect($list, $local)), $send);
            $held = array_values(array_diff(array_merge($to, $cc, $bcc), $local));
        }

        if (array_merge($send['to'], $send['cc'], $send['bcc']) !== []) {
            // sendmail runs with -t, so Bcc recipients are delivered and the header is stripped.
            $ok = @mail(
                $send['to'] !== [] ? implode(', ', $send['to']) : 'undisclosed-recipients:;',
                $encodedSubject,
                $body,
                implode("\r\n", array_merge($headers, $this->recipientHeaders($send['cc'], $send['bcc']))),
                '-f'.$from
            );
            if (! $ok) {
                throw new RuntimeException('Message could not be sent.');
            }
        }

        // mail() only hands the message to the local MTA; keep a copy in the
        // mailbox's Sent folder the same way a regular mail client does.
        $copyHeaders = array_merge($headers, $this->recipientHeaders($cc, $bcc));
        if ($held !== []) {
            $copyHeaders[] = 'X-Dpanel-Sandbox: not sent to '.implode(', ', $held);
        }
        $raw = implode("\r\n", array_merge(['To: '.implode(', ', $to), 'Subject: '.$encodedSubject], $copyHeaders))
            ."\r\n\r\n".str_replace("\n", "\r\n", $body)."\r\n";
        $this->appendToSent($mailbox, $raw);

        return ['held' => $held];
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @return array<int, string>
     */
    private function recipientHeaders(array $cc, array $bcc): array
    {
        return array_merge(
            $cc !== [] ? ['Cc: '.implode(', ', $cc)] : [],
            $bcc !== [] ? ['Bcc: '.implode(', ', $bcc)] : [],
        );
    }

    /**
     * Addresses whose domain this server receives mail for.
     *
     * @param  array<int, string>  $addresses
     * @return array<int, string>
     */
    public function hostedRecipients(array $addresses): array
    {
        $domains = array_values(array_unique(array_map(
            fn (string $address): string => strtolower(substr((string) strrchr($address, '@'), 1)),
            $addresses,
        )));
        $hosted = Mailbox::query()
            ->whereIn(DB::raw('LOWER(domain)'), $domains)
            ->where('status', 'active')
            ->distinct()
            ->pluck('domain')
            ->map(fn ($domain): string => strtolower((string) $domain))
            ->all();

        return array_values(array_filter(
            $addresses,
            fn (string $address): bool => in_array(strtolower(substr((string) strrchr($address, '@'), 1)), $hosted, true),
        ));
    }

    /**
     * Message-ID and References of a message, for threading a reply to it.
     *
     * @return array{message_id: string, references: string}
     */
    public function threadHeaders(Mailbox $mailbox, string $folder, int $uid): array
    {
        $stream = $this->open($mailbox, $folder);
        try {
            $raw = (string) @imap_fetchheader($stream, $uid, FT_UID);
        } finally {
            $this->close($stream);
        }
        // Unfold continuation lines so long References stay whole.
        $raw = (string) preg_replace('/\r?\n[ \t]+/', ' ', $raw);
        $header = fn (string $name): string => preg_match('/^'.$name.':\s*(.+)$/mi', $raw, $match) ? trim($match[1]) : '';
        $messageId = preg_match('/<[^<>\s]+>/', $header('Message-ID'), $match) ? $match[0] : '';

        // Keep the chain short enough for a header line and free of junk.
        preg_match_all('/<[^<>\s]+>/', $header('References'), $refs);

        return ['message_id' => $messageId, 'references' => implode(' ', array_slice($refs[0], -10))];
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

        // APPEND only says TRYCREATE when the folder is missing, so keep the
        // CREATE error: it names the real cause (e.g. Maildir permissions).
        $createError = null;
        if ($sentFolder === null) {
            $sentFolder = 'Sent';
            if (@imap_createmailbox($stream, imap_utf7_encode($prefix.$sentFolder))) {
                @imap_subscribe($stream, imap_utf7_encode($prefix.$sentFolder));
            } else {
                $createError = imap_last_error() ?: null;
            }
        }

        $appended = @imap_append($stream, $prefix.$sentFolder, $raw, '\\Seen');
        $error = imap_last_error();
        $this->close($stream);

        if (! $appended) {
            if ($createError !== null) {
                Log::warning('Unable to create Sent folder.', ['mailbox' => $mailbox->email, 'error' => $createError]);
                $error = "the Sent folder could not be created ({$createError})";
            }
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
     * Names this server stamps into Authentication-Results (OpenDKIM uses
     * the machine's hostname by default).
     *
     * @return array<int, string>
     */
    private function authServIds(): array
    {
        return Cache::remember('mail:authserv-ids', 3600, fn (): array => array_values(array_unique(array_filter(array_map(
            fn ($name): string => strtolower(trim((string) $name)),
            [gethostname(), @shell_exec('postconf -h myhostname 2>/dev/null'), config('serverpanel.mail.hostname')],
        )))));
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
        // Drop errors left by earlier calls so the one reported below is ours.
        imap_errors();
        $stream = @imap_open($mailboxPath, (string) $mailbox->email, $clientPassword, 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if ($stream === false) {
            $error = imap_last_error() ?: 'IMAP login failed.';
            Log::warning('IMAP open failed.', ['mailbox' => $mailbox->email, 'folder' => $folder, 'errors' => imap_errors() ?: []]);
            // Login worked but SELECT did not: the folder was deleted or renamed elsewhere.
            if (strcasecmp($folder, 'INBOX') !== 0 && preg_match("/doesn't exist|does not exist|no such mailbox|nonexistent/i", $error)) {
                MailboxSyncState::query()->where('mailbox_id', $mailbox->id)->update(['folders_synced_at' => null]);
                throw new RuntimeException("Folder \"{$folder}\" was not found on the mail server.", self::FOLDER_MISSING);
            }
            throw new RuntimeException($error);
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
            // \Noselect entries are namespace parents that cannot be opened.
            if ($folder === '' || ((int) ($mailbox->attributes ?? 0) & LATT_NOSELECT)) {
                continue;
            }

            $status = @imap_status($stream, $prefix.$folder, SA_MESSAGES | SA_UNSEEN);
            $folders[] = [
                'name' => $folder,
                'unread' => is_object($status) ? (int) ($status->unseen ?? 0) : 0,
                'exists' => is_object($status) ? (int) ($status->messages ?? 0) : 0,
            ];
        }

        // Like Roundcube: Sent, Drafts, Junk and Trash always exist, so the
        // sidebar never offers a folder that IMAP cannot open. Runs only
        // while one is missing, at most once per folder-list refresh.
        foreach ($this->missingDefaultFolders(array_column($folders, 'name')) as $name) {
            if (@imap_createmailbox($stream, imap_utf7_encode($prefix.$name))) {
                @imap_subscribe($stream, imap_utf7_encode($prefix.$name));
                $folders[] = ['name' => $name, 'unread' => 0, 'exists' => 0];
            }
        }
        // A failed create (another namespace layout) must not surface later
        // as the error of an unrelated call.
        imap_errors();

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
     * @param  array<int, string>  $existing
     * @return array<int, string>
     */
    private function missingDefaultFolders(array $existing): array
    {
        $missing = [];
        foreach (self::DEFAULT_FOLDERS as $name => $pattern) {
            if (preg_grep($pattern, $existing) === []) {
                $missing[] = $name;
            }
        }

        return $missing;
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
        if (is_object($status)) {
            $uidValidity = (int) ($status->uidvalidity ?? 0);
            $count = (int) ($status->messages ?? 0);
            $uidNext = (int) ($status->uidnext ?? 0);
            $unseen = (int) ($status->unseen ?? 0);
        } else {
            // STATUS failed (seen on a mailbox's very first sync, which then
            // showed an error only on the first open). The stream already has
            // this folder selected, so read the counts from it instead; an
            // unknown UIDNEXT just forces a full comparison.
            Log::warning('IMAP STATUS failed; using the selected folder instead.', [
                'mailbox' => $mailbox->email,
                'folder' => $folder,
                'errors' => imap_errors() ?: [],
            ]);
            $check = @imap_check($stream);
            if (! is_object($check)) {
                throw new RuntimeException(imap_last_error() ?: 'Unable to read folder status.');
            }
            $uidValidity = 0;
            $count = (int) ($check->Nmsgs ?? 0);
            $uidNext = 0;
            $unseen = $count === 0 ? 0 : count(@imap_search($stream, 'UNSEEN', SE_UID) ?: []);
        }

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

        // Decided once per message from the header block and kept with the cache.
        if ($metadata !== null && $metadata->sender_verified === null) {
            $headers = (string) @imap_fetchheader($stream, $uid, FT_UID);
            $metadata->forceFill(['sender_verified' => $this->analyzer->verifiedDomain($headers, $this->authServIds()) !== null])->save();
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
            // DMARC or aligned DKIM passed here, so a BIMI logo may be shown.
            'sender_verified' => (bool) $metadata?->sender_verified,
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
