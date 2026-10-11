<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\MailboxContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webmail address book. Saved contacts are the ones the user added; the rest
 * are remembered from sent mail so the recipient fields can suggest them.
 */
class MailContactController extends Controller
{
    public function index(string $token, string $id): JsonResponse
    {
        $mailbox = $this->mailbox($id);

        $contacts = MailboxContact::query()
            ->where('mailbox_id', $mailbox->id)
            ->orderByDesc('starred')
            ->orderByDesc('last_used_at')
            ->orderBy('email')
            ->limit(2000)
            ->get()
            ->map(fn (MailboxContact $contact): array => $contact->toClient())
            ->all();

        return response()->json(['success' => true, 'contacts' => $contacts]);
    }

    public function store(Request $request, string $token, string $id): JsonResponse
    {
        $mailbox = $this->mailbox($id);
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $contact = MailboxContact::query()->updateOrCreate(
            ['mailbox_id' => $mailbox->id, 'email' => strtolower(trim($validated['email']))],
            ['name' => trim((string) ($validated['name'] ?? '')) ?: null, 'starred' => true],
        );

        return response()->json(['success' => true, 'message' => 'Contact saved.', 'contact' => $contact->toClient()]);
    }

    public function destroy(string $token, string $id, string $contact): JsonResponse
    {
        $mailbox = $this->mailbox($id);
        MailboxContact::query()->where('mailbox_id', $mailbox->id)->whereKey($contact)->delete();

        return response()->json(['success' => true, 'message' => 'Contact removed.']);
    }

    /**
     * Remember everyone a message went to, keeping names the user typed.
     *
     * @param  array<int, string>  $recipients  raw "Name <addr>" or "addr" entries
     */
    public static function remember(Mailbox $mailbox, array $recipients): void
    {
        foreach ($recipients as $entry) {
            $entry = trim($entry);
            $name = '';
            if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^<>]+)>\s*$/', $entry, $match)) {
                [$name, $entry] = [trim($match[1]), trim($match[2])];
            }
            $email = strtolower($entry);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $contact = MailboxContact::query()->firstOrNew(['mailbox_id' => $mailbox->id, 'email' => $email]);
            if ($name !== '' && (string) $contact->name === '') {
                $contact->name = $name;
            }
            $contact->last_used_at = now();
            $contact->save();
        }
    }

    private function mailbox(string $id): Mailbox
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        return $mailbox;
    }
}
