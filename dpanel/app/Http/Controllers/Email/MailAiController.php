<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Services\AiGateway\Exceptions\AiGatewayException;
use App\Services\Mail\MailAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Webmail AI: summary, reply draft, new email from a description and draft
 * polish. None of them sends anything.
 */
class MailAiController extends Controller
{
    public function __construct(private readonly MailAi $ai) {}

    public function summary(Request $request, string $token, string $id): JsonResponse
    {
        $mailbox = $this->mailbox($id);
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:255'],
            'uid' => ['required', 'integer', 'min:1'],
        ]);

        return $this->answer(fn (): string => $this->ai->summarize(
            $mailbox, (string) $validated['folder'], (int) $validated['uid'], $request->user()?->id,
        ));
    }

    public function reply(Request $request, string $token, string $id): JsonResponse
    {
        $mailbox = $this->mailbox($id);
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:255'],
            'uid' => ['required', 'integer', 'min:1'],
            'tone' => ['nullable', 'string', Rule::in(MailAi::tones())],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->answer(fn (): string => $this->ai->draftReply(
            $mailbox,
            (string) $validated['folder'],
            (int) $validated['uid'],
            (string) ($validated['tone'] ?? 'friendly'),
            (string) ($validated['instructions'] ?? ''),
            $request->user()?->id,
        ));
    }

    /** A new email from a short description: subject and body. */
    public function compose(Request $request, string $token, string $id): JsonResponse
    {
        $mailbox = $this->mailbox($id);
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'to' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $email = $this->ai->compose($mailbox, (string) $validated['description'], (string) ($validated['to'] ?? ''), $request->user()?->id);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'subject' => $email['subject'], 'text' => $email['body']]);
    }

    /** The draft with one change: grammar, shorter, tone or translation. */
    public function polish(Request $request, string $token, string $id): JsonResponse
    {
        $this->mailbox($id);
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:20000'],
            'action' => ['required', 'string', Rule::in(MailAi::polishActions())],
        ]);

        return $this->answer(fn (): string => $this->ai->polish((string) $validated['text'], (string) $validated['action'], $request->user()?->id));
    }

    private function mailbox(string $id): Mailbox
    {
        $mailbox = Mailbox::query()->visibleTo(auth()->user())->find($id);
        abort_if($mailbox === null, 404);
        // The buttons are hidden without a model; a direct call gets a clear answer.
        abort_unless($this->ai->available(), 404, 'AI Gateway has no active model.');

        return $mailbox;
    }

    /** @param  \Closure(): string  $run */
    private function answer(\Closure $run): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'text' => $run()]);
        } catch (AiGatewayException|RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
