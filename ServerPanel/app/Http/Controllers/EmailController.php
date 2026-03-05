<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    private const STORAGE_FILE = 'email-accounts.json';
    private const WEBSITE_STORAGE_FILE = 'website-requests.json';

    public function create(): Response
    {
        return Inertia::render('CreateEmail', [
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    public function index(): Response
    {
        $mailboxes = collect($this->readMailboxes())
            ->sortByDesc('created_at')
            ->values()
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

        $mailboxes = $this->readMailboxes();
        $exists = collect($mailboxes)->contains(fn (array $item) => strtolower((string) ($item['email'] ?? '')) === $email);
        if ($exists) {
            return redirect()->route('emails.create')->with('error', "Mailbox {$email} already exists.");
        }

        $mailboxes[] = [
            'id' => (string) str()->uuid(),
            'domain' => $domain,
            'mailbox' => $mailbox,
            'email' => $email,
            'password' => (string) $validated['password'],
            'quota_mb' => (int) $validated['quota_mb'],
            'forwarding_to' => trim((string) ($validated['forwarding_to'] ?? '')),
            'status' => 'active',
            'created_at' => now()->toIso8601String(),
        ];

        $this->writeMailboxes($mailboxes);

        return redirect()->route('emails.list')->with('success', "Mailbox {$email} created successfully.");
    }

    public function edit(string $id): Response
    {
        $mailbox = collect($this->readMailboxes())->firstWhere('id', $id);
        abort_if($mailbox === null, 404);

        return Inertia::render('EditEmail', [
            'mailbox' => $mailbox,
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $domain = strtolower(trim((string) $validated['domain']));
        $mailboxName = strtolower(trim((string) $validated['mailbox']));
        $email = "{$mailboxName}@{$domain}";

        $mailboxes = collect($this->readMailboxes());
        $exists = $mailboxes->contains(function (array $item) use ($id, $email) {
            return ($item['id'] ?? null) !== $id && strtolower((string) ($item['email'] ?? '')) === $email;
        });

        if ($exists) {
            return redirect()->route('emails.edit', $id)->with('error', "Mailbox {$email} already exists.");
        }

        $updated = $mailboxes->map(function (array $item) use ($id, $validated, $domain, $mailboxName, $email) {
            if (($item['id'] ?? null) !== $id) {
                return $item;
            }

            $item['domain'] = $domain;
            $item['mailbox'] = $mailboxName;
            $item['email'] = $email;
            $item['password'] = (string) $validated['password'];
            $item['quota_mb'] = (int) $validated['quota_mb'];
            $item['forwarding_to'] = trim((string) ($validated['forwarding_to'] ?? ''));
            $item['updated_at'] = now()->toIso8601String();

            return $item;
        })->values()->all();

        $this->writeMailboxes($updated);

        return redirect()->route('emails.list')->with('success', "Mailbox {$email} updated successfully.");
    }

    public function destroy(string $id): RedirectResponse
    {
        $mailboxes = collect($this->readMailboxes());
        $before = $mailboxes->count();
        $after = $mailboxes->reject(fn (array $item) => ($item['id'] ?? null) === $id)->values()->all();

        if (count($after) === $before) {
            return redirect()->route('emails.list')->with('error', 'Mailbox not found.');
        }

        $this->writeMailboxes($after);

        return redirect()->route('emails.list')->with('success', 'Mailbox deleted successfully.');
    }

    public function login(string $id)
    {
        $mailbox = collect($this->readMailboxes())->firstWhere('id', $id);
        abort_if($mailbox === null, 404);

        return response()->view('webmail.autologin', [
            'targetUrl' => (string) env('WEBMAIL_URL', request()->getSchemeAndHttpHost().'/webmail/'),
            'email' => (string) ($mailbox['email'] ?? ''),
            'password' => (string) ($mailbox['password'] ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'mailbox' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'quota_mb' => ['required', 'integer', 'min:1', 'max:102400'],
            'forwarding_to' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readMailboxes(): array
    {
        if (! Storage::exists(self::STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::STORAGE_FILE), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $mailboxes
     */
    private function writeMailboxes(array $mailboxes): void
    {
        Storage::put(self::STORAGE_FILE, json_encode($mailboxes, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<int, string>
     */
    private function readWebsiteDomains(): array
    {
        if (! Storage::exists(self::WEBSITE_STORAGE_FILE)) {
            return [];
        }

        $decoded = json_decode((string) Storage::get(self::WEBSITE_STORAGE_FILE), true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->pluck('domain')
            ->filter(fn ($domain) => is_string($domain) && $domain !== '')
            ->unique()
            ->values()
            ->all();
    }
}
