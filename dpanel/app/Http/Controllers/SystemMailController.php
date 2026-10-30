<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\Mail\SystemMailer;
use App\Support\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Admin page for the system email the panel sends login codes, password
 * reset and verification links from.
 */
class SystemMailController extends Controller
{
    public function __construct(
        private readonly SystemMailer $systemMailer,
        private readonly MailSettings $settings,
    ) {}

    public function show(Request $request): Response
    {
        $panelHost = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));

        return Inertia::render('Email/Manage/SystemMail', [
            'status' => $this->systemMailer->status(),
            'selectedId' => $this->settings->read()['system_mailbox_id'],
            'mailboxes' => Mailbox::query()->orderBy('email')->get(['id', 'email', 'status', 'delivery_mode', 'client_password'])
                ->map(fn (Mailbox $mailbox): array => [
                    'id' => $mailbox->id,
                    'email' => $mailbox->email,
                    'problem' => $this->systemMailer->problemWith($mailbox),
                ])->all(),
            // noreply@<panel domain> is the usual choice.
            'suggestedEmail' => $panelHost !== '' && ! filter_var($panelHost, FILTER_VALIDATE_IP) ? 'noreply@'.$panelHost : '',
            'testRecipient' => (string) $request->user()?->email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['mailbox_id' => ['required', 'string', 'exists:mailboxes,id']]);
        $mailbox = Mailbox::query()->findOrFail($validated['mailbox_id']);
        if ($problem = $this->systemMailer->problemWith($mailbox)) {
            return back()->with('error', $problem);
        }

        $this->settings->write(['system_mailbox_id' => $mailbox->id]);

        return back()->with('success', "The panel now sends its mail from {$mailbox->email}. Send a test to make sure it arrives.");
    }

    public function destroy(): RedirectResponse
    {
        $this->settings->write(['system_mailbox_id' => null]);

        return back()->with('success', 'System email removed. The panel uses the mailer from .env again.');
    }

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate(['to' => ['required', 'email', 'max:255']]);
        if (! $this->systemMailer->status()['ready']) {
            return response()->json(['ok' => false, 'message' => $this->systemMailer->status()['problem']], 422);
        }

        try {
            $this->systemMailer->sendTest($validated['to']);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Sending failed: '.$e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => "Test email sent to {$validated['to']}. Check the inbox and the spam folder."]);
    }
}
