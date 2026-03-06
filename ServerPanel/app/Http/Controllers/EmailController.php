<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('CreateEmail', [
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    public function index(): Response
    {
        $mailboxes = Mailbox::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Mailbox $mailbox): array => $mailbox->toArray())
            ->all();

        return Inertia::render('ListEmails', [
            'mailboxes' => $mailboxes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $domain = strtolower(trim((string) $validated['domain']));
        $mailbox = strtolower(trim((string) $validated['mailbox']));
        $email = "{$mailbox}@{$domain}";

        if (Mailbox::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return redirect()->route('emails.create')->with('error', "Mailbox {$email} already exists.");
        }

        Mailbox::query()->create([
            'id' => (string) str()->uuid(),
            'domain' => $domain,
            'mailbox' => $mailbox,
            'email' => $email,
            'password' => (string) $validated['password'],
            'quota_mb' => (int) $validated['quota_mb'],
            'forwarding_to' => trim((string) ($validated['forwarding_to'] ?? '')),
            'status' => 'active',
        ]);

        return redirect()->route('emails.list')->with('success', "Mailbox {$email} created successfully.");
    }

    public function edit(string $id): Response
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        return Inertia::render('EditEmail', [
            'mailbox' => $mailbox->toArray(),
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $domain = strtolower(trim((string) $validated['domain']));
        $mailboxName = strtolower(trim((string) $validated['mailbox']));
        $email = "{$mailboxName}@{$domain}";

        $mailboxRecord = Mailbox::query()->find($id);
        if ($mailboxRecord === null) {
            return redirect()->route('emails.list')->with('error', 'Mailbox not found.');
        }

        $exists = Mailbox::query()
            ->where('id', '!=', $id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($exists) {
            return redirect()->route('emails.edit', $id)->with('error', "Mailbox {$email} already exists.");
        }

        $mailboxRecord->fill([
            'domain' => $domain,
            'mailbox' => $mailboxName,
            'email' => $email,
            'password' => (string) $validated['password'],
            'quota_mb' => (int) $validated['quota_mb'],
            'forwarding_to' => trim((string) ($validated['forwarding_to'] ?? '')),
        ]);
        $mailboxRecord->save();

        return redirect()->route('emails.list')->with('success', "Mailbox {$email} updated successfully.");
    }

    public function destroy(string $id): RedirectResponse
    {
        $deleted = Mailbox::query()->where('id', $id)->delete();
        if ($deleted === 0) {
            return redirect()->route('emails.list')->with('error', 'Mailbox not found.');
        }

        return redirect()->route('emails.list')->with('success', 'Mailbox deleted successfully.');
    }

    public function login(string $id)
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        return response()->view('webmail.autologin', [
            'targetUrl' => (string) env('WEBMAIL_URL', request()->getSchemeAndHttpHost().'/roundcube/'),
            'email' => (string) ($mailbox->email ?? ''),
            'password' => (string) ($mailbox->password ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?=.{1,253}$)(?!-)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/',
            ],
            'mailbox' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'quota_mb' => ['required', 'integer', 'min:1', 'max:102400'],
            'forwarding_to' => ['nullable', 'email', 'max:255'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function readWebsiteDomains(): array
    {
        return Website::query()
            ->pluck('domain')
            ->filter(fn ($domain) => is_string($domain) && trim($domain) !== '')
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
