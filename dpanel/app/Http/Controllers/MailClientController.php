<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMailboxMetadataJob;
use App\Models\Mailbox;
use App\Models\MailboxMessageMetadata;
use App\Services\Mail\MailAvatarResolver;
use App\Services\Mail\MailboxImapService;
use App\Services\Mail\MailImageProxy;
use App\Services\Mail\MailMessageAnalyzer;
use App\Services\Mail\MailSpamAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class MailClientController extends Controller
{
    public function show(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): Response|RedirectResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $folder = (string) $request->query('folder', 'INBOX');
        $relatedMailboxes = Mailbox::query()
            ->where('domain', $mailbox->domain)
            ->orderBy('email')
            ->get(['id', 'email', 'domain'])
            ->map(fn (Mailbox $item): array => [
                'id' => $item->id,
                'email' => $item->email,
                'domain' => $item->domain,
            ])
            ->all();

        return Inertia::render('Email/Mailbox/Client', [
            'mailbox' => [
                'id' => $mailbox->id,
                'email' => $mailbox->email,
                'domain' => $mailbox->domain,
                'quota_mb' => $mailbox->quota_mb,
                'status' => $mailbox->status,
                'delivery_mode' => $mailbox->delivery_mode ?: Mailbox::MODE_LIVE,
                // Changes with each upload, so the browser refetches the photo.
                'avatar_version' => (string) $mailbox->avatar_path !== '' ? (int) $mailbox->updated_at?->timestamp : 0,
                // Approximate: sizes of messages in folders that have been synced.
                'used_bytes' => (int) MailboxMessageMetadata::query()->where('mailbox_id', $mailbox->id)->sum('size'),
            ],
            'relatedMailboxes' => $relatedMailboxes,
            'selectedFolder' => $folder,
            'folders' => [],
            'messages' => [],
            'message' => null,
            'loadingError' => null,
            'loadEndpoint' => route('mailbox.data', ['token' => $token, 'id' => $id]),
            'messageEndpoint' => route('mailbox.message', ['token' => $token, 'id' => $id]),
            'rawEndpoint' => route('mailbox.raw', ['token' => $token, 'id' => $id]),
            'avatarEndpoint' => route('mailbox.avatar', ['token' => $token]),
            'photoEndpoint' => route('mailbox.photo', ['token' => $token, 'id' => $id]),
            'rawDownloadEndpoint' => route('mailbox.raw-download', ['token' => $token, 'id' => $id]),
            'sendEndpoint' => route('mailbox.send', ['token' => $token, 'id' => $id]),
            'deleteEndpoint' => route('mailbox.delete-message', ['token' => $token, 'id' => $id]),
            'markReadEndpoint' => route('mailbox.mark-read', ['token' => $token, 'id' => $id]),
            'bulkEndpoint' => route('mailbox.bulk', ['token' => $token, 'id' => $id]),
            'threadEndpoint' => route('mailbox.thread', ['token' => $token, 'id' => $id]),
            'contactsEndpoint' => route('mailbox.contacts', ['token' => $token, 'id' => $id]),
            'composeDefaults' => [
                'to' => '',
                'subject' => '',
            ],
        ]);
    }

    public function data(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $folder = (string) $request->query('folder', 'INBOX');
        $uid = $request->integer('uid') ?: null;

        if (! $request->boolean('refresh')) {
            $cached = $mailboxImapService->cachedMailbox($mailbox, $folder);
            $cachedMessage = $uid !== null ? $mailboxImapService->cachedMessage($mailbox, $folder, $uid) : null;
            if ($cached !== null && ($uid === null || $cachedMessage !== null)) {
                SyncMailboxMetadataJob::dispatch((string) $mailbox->id, $folder)->afterResponse();

                return response()->json([
                    'success' => true,
                    'message' => null,
                    'folders' => $cached['folders'],
                    'messages' => $cached['messages'],
                    'messageData' => $cachedMessage,
                    'cached' => true,
                    'syncing' => true,
                ]);
            }
        }

        try {
            $data = $mailboxImapService->loadMailbox($mailbox, $folder, $uid);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'folder_missing' => $e->getCode() === MailboxImapService::FOLDER_MISSING,
                'folders' => [],
                'messages' => [],
                'messageData' => null,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => null,
            'folders' => $data['folders'],
            'messages' => $data['messages'],
            'messageData' => $data['message'],
            'cached' => false,
            'syncing' => false,
        ]);
    }

    public function message(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'folder' => ['required', 'string'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $message = $mailboxImapService->openMessage($mailbox, (string) $validated['folder'], (int) $validated['uid']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'messageData' => null]);
        }

        if ($message === null) {
            return response()->json(['success' => false, 'message' => 'Message not found.', 'messageData' => null]);
        }

        return response()->json(['success' => true, 'message' => null, 'messageData' => $message]);
    }

    /**
     * The conversation a message belongs to, oldest first.
     */
    public function thread(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'folder' => ['required', 'string'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $messages = $mailboxImapService->thread($mailbox, (string) $validated['folder'], (int) $validated['uid']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'messages' => []]);
        }

        return response()->json(['success' => true, 'messages' => $messages]);
    }

    /**
     * "Show original": the message source plus header and spam analysis. Loaded
     * only when the Original tab is opened, so reading mail costs nothing extra.
     */
    public function raw(Request $request, string $token, string $id, MailboxImapService $mailboxImapService, MailMessageAnalyzer $analyzer, MailSpamAnalyzer $spamAnalyzer): JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'folder' => ['required', 'string'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $raw = $mailboxImapService->rawMessage($mailbox, (string) $validated['folder'], (int) $validated['uid']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }

        if ($raw === null) {
            return response()->json(['success' => false, 'message' => 'Message not found.']);
        }

        $analysis = $analyzer->analyze($raw['headers']);
        $analysis['spam_check'] = $spamAnalyzer->analyze($raw['raw'], $analysis);

        return response()->json([
            'success' => true,
            'message' => null,
            // Raw bytes may be in any charset; JSON needs UTF-8.
            'raw' => mb_convert_encoding($raw['raw'], 'UTF-8', 'UTF-8'),
            'size' => $raw['size'],
            'truncated' => $raw['truncated'],
            'analysis' => $analysis,
        ]);
    }

    /**
     * The original message as an .eml file, streamed without loading it into memory.
     */
    public function rawDownload(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): \Symfony\Component\HttpFoundation\Response
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'folder' => ['required', 'string'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        $file = fopen('php://temp/maxmemory:1048576', 'w+b');
        try {
            $headers = $mailboxImapService->saveRawMessage($mailbox, (string) $validated['folder'], (int) $validated['uid'], $file);
        } catch (RuntimeException $e) {
            fclose($file);
            abort(502, $e->getMessage());
        }
        if ($headers === null) {
            fclose($file);
            abort(404);
        }

        $size = (int) ftell($file);
        rewind($file);

        return response()->streamDownload(function () use ($file): void {
            fpassthru($file);
            fclose($file);
        }, 'message-'.(int) $validated['uid'].'.eml', [
            'Content-Type' => 'message/rfc822',
            'Content-Length' => (string) $size,
        ]);
    }

    /**
     * The sender's picture (own mailbox photo, BIMI logo or Gravatar), or 404
     * so the page keeps the initials. ?bimi=1 is only sent for messages whose
     * sender passed DMARC or aligned DKIM here.
     */
    public function avatar(Request $request, string $token, MailAvatarResolver $resolver): \Symfony\Component\HttpFoundation\Response
    {
        $image = $resolver->resolve((string) $request->query('email', ''), $request->boolean('bimi'));
        abort_if($image === null, 404);

        return response($image['body'], 200, [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // BIMI logos are SVG: never let one run anything.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /**
     * Upload the mailbox's profile photo, shown as its avatar to other
     * mailboxes on this server (like a Gmail account picture).
     */
    public function uploadPhoto(Request $request, string $token, string $id): JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:1024', 'dimensions:max_width=4096,max_height=4096'],
        ]);

        $disk = Storage::disk('local');
        if ($mailbox->avatar_path) {
            $disk->delete($mailbox->avatar_path);
        }
        $path = $validated['photo']->storeAs('mail-avatars', $mailbox->id.'.'.$validated['photo']->extension(), 'local');
        $mailbox->forceFill(['avatar_path' => $path])->save();

        return response()->json(['success' => true, 'message' => 'Profile photo updated.']);
    }

    public function deletePhoto(string $token, string $id): JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        if ($mailbox->avatar_path) {
            Storage::disk('local')->delete($mailbox->avatar_path);
        }
        $mailbox->forceFill(['avatar_path' => null])->save();

        return response()->json(['success' => true, 'message' => 'Profile photo removed.']);
    }

    /**
     * Remote image from an HTML message, served through the panel.
     */
    public function image(Request $request, string $token, MailImageProxy $imageProxy): \Symfony\Component\HttpFoundation\Response
    {
        $url = (string) $request->query('url', '');
        abort_unless($url !== '' && $imageProxy->validSignature($url, (string) $request->query('sig', '')), 403);

        $image = $imageProxy->fetch($url);
        abort_if($image === null, 404);

        return response($image['body'], 200, [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function send(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): RedirectResponse|JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'to' => ['required', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'bcc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'html' => ['nullable', 'string', 'max:2000000'],
            'folder' => ['nullable', 'string'],
            // The message being replied to, for In-Reply-To/References.
            'reply_folder' => ['nullable', 'string', 'max:255'],
            'reply_uid' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $thread = [];
            if (! empty($validated['reply_folder']) && ! empty($validated['reply_uid'])) {
                try {
                    $thread = $mailboxImapService->threadHeaders($mailbox, (string) $validated['reply_folder'], (int) $validated['reply_uid']);
                } catch (RuntimeException) {
                    // The original was moved or deleted; send unthreaded.
                }
            }
            $result = $mailboxImapService->sendMessage(
                $mailbox,
                $this->addresses((string) $validated['to']),
                (string) $validated['subject'],
                (string) $validated['body'],
                $this->addresses((string) ($validated['cc'] ?? '')),
                $this->addresses((string) ($validated['bcc'] ?? '')),
                (string) ($validated['html'] ?? ''),
                $thread,
            );
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('mailbox.open', ['token' => $token, 'id' => $id, 'folder' => (string) ($validated['folder'] ?? 'INBOX')])
                ->with('error', $e->getMessage());
        }

        MailContactController::remember($mailbox, preg_split('/[,;]+/', implode(',', [
            (string) $validated['to'], (string) ($validated['cc'] ?? ''), (string) ($validated['bcc'] ?? ''),
        ])) ?: []);

        $notice = $result['held'] === []
            ? 'Message sent successfully.'
            : 'Sandbox mode: not sent to '.implode(', ', $result['held']).'. A copy is in Sent.';

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $notice, 'held' => $result['held']]);
        }

        return redirect()
            ->route('mailbox.open', ['token' => $token, 'id' => $id, 'folder' => (string) ($validated['folder'] ?? 'INBOX')])
            ->with('success', $notice);
    }

    public function delete(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): RedirectResponse|JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'folder' => ['required', 'string'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $mailboxImapService->deleteMessage($mailbox, (string) $validated['folder'], (int) $validated['uid']);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('mailbox.open', ['token' => $token, 'id' => $id, 'folder' => (string) $validated['folder']])
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Message deleted.']);
        }

        return redirect()
            ->route('mailbox.open', ['token' => $token, 'id' => $id, 'folder' => (string) $validated['folder']])
            ->with('success', 'Message deleted.');
    }

    public function markRead(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'folder' => ['required', 'string'],
            'uid' => ['required', 'integer', 'min:1'],
            'seen' => ['required', 'boolean'],
        ]);

        try {
            $mailboxImapService->markRead($mailbox, (string) $validated['folder'], (int) $validated['uid'], (bool) $validated['seen']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => (bool) $validated['seen'] ? 'Marked as read.' : 'Marked as unread.']);
    }

    public function bulk(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): JsonResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'folder' => ['required', 'string'],
            'action' => ['required', 'in:delete,read,unread'],
            'uids' => ['required', 'array', 'min:1', 'max:1000'],
            'uids.*' => ['integer', 'min:1'],
        ]);

        $folder = (string) $validated['folder'];
        $uids = array_map('intval', $validated['uids']);

        try {
            match ($validated['action']) {
                'delete' => $mailboxImapService->deleteMessages($mailbox, $folder, $uids),
                'read' => $mailboxImapService->setSeen($mailbox, $folder, $uids, true),
                'unread' => $mailboxImapService->setSeen($mailbox, $folder, $uids, false),
            };
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $count = count($uids);
        $noun = $count === 1 ? 'message' : 'messages';

        return response()->json([
            'success' => true,
            'message' => match ($validated['action']) {
                'delete' => "{$count} {$noun} deleted.",
                'read' => "{$count} {$noun} marked as read.",
                'unread' => "{$count} {$noun} marked as unread.",
            },
        ]);
    }

    /**
     * Accepts "a@x.com, Name <b@y.com>; c@z.com" and returns bare addresses.
     *
     * @return array<int, string>
     */
    private function addresses(string $value): array
    {
        $addresses = [];
        // Quoted display names may contain commas ("Doe, J" <j@x.com>).
        $value = (string) preg_replace('/"[^"]*"/', '', $value);
        foreach (preg_split('/[,;]+/', $value) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/<([^<>]+)>/', $part, $match)) {
                $part = trim($match[1]);
            }
            $addresses[] = $part;
        }

        return array_values(array_unique($addresses));
    }
}
