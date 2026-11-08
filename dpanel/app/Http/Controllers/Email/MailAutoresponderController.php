<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Models\MailboxAutoresponder;
use App\Services\Mail\Backend\MailBackend;
use App\Services\Mail\Backend\MailBackendException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A mailbox's automatic reply (backlog M1). */
class MailAutoresponderController extends Controller
{
    public function __construct(private readonly MailBackend $mail) {}

    public function show(Request $request, string $token, string $id): JsonResponse
    {
        $mailbox = Mailbox::query()->visibleTo($request->user())->findOrFail($id);
        $reply = MailboxAutoresponder::query()->where('mailbox_id', $mailbox->id)->first();

        return response()->json([
            'supported' => $this->mail->supportsAutoresponder(),
            'autoresponder' => $reply?->only(['enabled', 'subject', 'body', 'interval_days', 'starts_on', 'ends_on']),
        ]);
    }

    public function update(Request $request, string $token, string $id): JsonResponse
    {
        $mailbox = Mailbox::query()->visibleTo($request->user())->findOrFail($id);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'subject' => ['required_if:enabled,true', 'nullable', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'body' => ['required_if:enabled,true', 'nullable', 'string', 'max:10000'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        abort_unless($this->mail->supportsAutoresponder(), 404);

        $reply = MailboxAutoresponder::query()->firstOrNew(['mailbox_id' => $mailbox->id]);
        $reply->fill([
            'enabled' => (bool) $validated['enabled'],
            'subject' => (string) ($validated['subject'] ?? $reply->subject ?? ''),
            'body' => (string) ($validated['body'] ?? $reply->body ?? ''),
            'interval_days' => (int) ($validated['interval_days'] ?? 1),
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
        ]);

        try {
            // The server first: a reply the server refused is not saved as on.
            $this->mail->setAutoresponder($mailbox, $reply->enabled ? $reply->data() : null);
        } catch (MailBackendException $e) {
            return response()->json(['ok' => false, 'message' => 'The automatic reply was not saved: '.$e->getMessage()], 422);
        }
        $reply->save();

        return response()->json([
            'ok' => true,
            'message' => $reply->enabled ? "{$mailbox->email} now replies automatically." : "{$mailbox->email} no longer replies automatically.",
        ]);
    }
}
