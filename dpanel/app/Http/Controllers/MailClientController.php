<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMailboxMetadataJob;
use App\Models\Mailbox;
use App\Models\MailboxMessageMetadata;
use App\Services\Mail\MailboxImapService;
use App\Services\Mail\MailImageProxy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'sendEndpoint' => route('mailbox.send', ['token' => $token, 'id' => $id]),
            'deleteEndpoint' => route('mailbox.delete-message', ['token' => $token, 'id' => $id]),
            'markReadEndpoint' => route('mailbox.mark-read', ['token' => $token, 'id' => $id]),
            'bulkEndpoint' => route('mailbox.bulk', ['token' => $token, 'id' => $id]),
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
            'folder' => ['nullable', 'string'],
        ]);

        try {
            $mailboxImapService->sendMessage(
                $mailbox,
                $this->addresses((string) $validated['to']),
                (string) $validated['subject'],
                (string) $validated['body'],
                $this->addresses((string) ($validated['cc'] ?? '')),
                $this->addresses((string) ($validated['bcc'] ?? ''))
            );
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('mailbox.open', ['token' => $token, 'id' => $id, 'folder' => (string) ($validated['folder'] ?? 'INBOX')])
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Message sent successfully.']);
        }

        return redirect()
            ->route('mailbox.open', ['token' => $token, 'id' => $id, 'folder' => (string) ($validated['folder'] ?? 'INBOX')])
            ->with('success', 'Message sent successfully.');
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
