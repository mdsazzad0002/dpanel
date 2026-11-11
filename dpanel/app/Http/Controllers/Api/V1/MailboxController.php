<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\EmailController;
use App\Models\Mailbox;
use App\Models\Website;
use App\Support\MailPasswordHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mailboxes through the public API. Creating and deleting go through the
 * panel's EmailController, so storage, package limits and mail domain set-up
 * are the same. Unlike the page, a mailbox can only be made on the domain of
 * a website the user can see.
 */
class MailboxController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $mailboxes = Mailbox::query()->visibleTo($request->user())->orderBy('email')->get();

        return response()->json(['data' => $mailboxes->map(fn (Mailbox $mailbox) => $this->present($mailbox))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:102400'],
            'forwarding_to' => ['nullable', 'email', 'max:255'],
        ]);
        [$local, $domain] = explode('@', strtolower(trim($validated['email'])), 2);
        $ownsDomain = Website::query()->visibleTo($request->user())->whereRaw('LOWER(domain) = ?', [$domain])->exists();
        if (! $ownsDomain) {
            return response()->json(['message' => 'Mailboxes can only be made on the domain of one of your websites.', 'errors' => ['email' => ["{$domain} is not one of your websites."]]], 422);
        }

        $response = app(EmailController::class)->store($this->panelRequest($request, [
            'domain' => $domain,
            'mailbox' => $local,
            'password' => $validated['password'],
            'quota_mb' => $validated['quota_mb'] ?? 1024,
            'forwarding_to' => $validated['forwarding_to'] ?? null,
        ]));
        if ($response->getStatusCode() >= 300) {
            return $this->panelError($response);
        }
        $mailbox = Mailbox::query()->whereRaw('LOWER(email) = ?', ["{$local}@{$domain}"])->first();

        return response()->json([
            'message' => (string) ($response->getData(true)['message'] ?? 'Mailbox created.'),
            'data' => $mailbox ? $this->present($mailbox) : null,
        ], 201);
    }

    public function password(Request $request, string $mailbox): JsonResponse
    {
        $found = Mailbox::query()->visibleTo($request->user())->whereKey($mailbox)->first();
        if ($found === null) {
            return $this->notFound('Mailbox');
        }
        $validated = $request->validate(['password' => ['required', 'string', 'min:8', 'max:255']]);

        // Dovecot reads the hash from the database, so nothing else needs to change.
        $found->forceFill([
            'password' => MailPasswordHash::make($validated['password']),
            'client_password' => $validated['password'],
        ])->save();

        return response()->json(['message' => "The password of {$found->email} was changed."]);
    }

    public function destroy(Request $request, string $mailbox): JsonResponse
    {
        $found = Mailbox::query()->visibleTo($request->user())->whereKey($mailbox)->first();
        if ($found === null) {
            return $this->notFound('Mailbox');
        }

        $result = app(EmailController::class)->deleteMailbox($found);

        return response()->json(['message' => $result['message']], $result['ok'] ? 200 : 502);
    }

    /** @return array<string, mixed> */
    private function present(Mailbox $mailbox): array
    {
        return [
            'id' => (string) $mailbox->id,
            'email' => (string) $mailbox->email,
            'domain' => (string) $mailbox->domain,
            'quota_mb' => (int) $mailbox->quota_mb,
            'forwarding_to' => $mailbox->forwarding_to ?: null,
            'status' => (string) $mailbox->status,
            'delivery_mode' => (string) ($mailbox->delivery_mode ?: Mailbox::MODE_LIVE),
            'created_at' => $mailbox->created_at?->toIso8601String(),
        ];
    }
}
