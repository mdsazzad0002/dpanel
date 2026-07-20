<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\Mail\MailboxImapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        return Inertia::render('Mailbox/Client', [
            'mailbox' => [
                'id' => $mailbox->id,
                'email' => $mailbox->email,
                'domain' => $mailbox->domain,
                'quota_mb' => $mailbox->quota_mb,
                'status' => $mailbox->status,
            ],
            'relatedMailboxes' => $relatedMailboxes,
            'selectedFolder' => $folder,
            'folders' => [],
            'messages' => [],
            'message' => null,
            'loadingError' => null,
            'loadEndpoint' => route('mailbox.data', ['token' => $token, 'id' => $id]),
            'sendEndpoint' => route('mailbox.send', ['token' => $token, 'id' => $id]),
            'deleteEndpoint' => route('mailbox.delete-message', ['token' => $token, 'id' => $id]),
            'markReadEndpoint' => route('mailbox.mark-read', ['token' => $token, 'id' => $id]),
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
        ]);
    }

    public function send(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): RedirectResponse
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        $validated = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'folder' => ['nullable', 'string'],
        ]);

        try {
            $mailboxImapService->sendMessage(
                $mailbox,
                (string) $validated['to'],
                (string) $validated['subject'],
                (string) $validated['body']
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

    public function delete(Request $request, string $token, string $id, MailboxImapService $mailboxImapService): RedirectResponse
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
}
