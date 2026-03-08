<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    public function webmailEntry(Request $request): RedirectResponse|HttpResponse
    {
        $configured = trim((string) env('WEBMAIL_URL', ''));
        if ($configured !== '') {
            $configuredTarget = rtrim($configured, '/');
            $currentRoundcubePath = rtrim($request->getSchemeAndHttpHost().'/roundcube', '/');

            if ($configuredTarget !== '' && strcasecmp($configuredTarget, $currentRoundcubePath) !== 0) {
                return redirect()->away($configuredTarget);
            }
        }

        return response()->view('webmail.missing', [
            'configuredUrl' => $configured,
            'defaultUrl' => $request->getSchemeAndHttpHost().'/roundcube/',
            'panelUrl' => $request->getSchemeAndHttpHost().'/emails/list',
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CreateEmail', [
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    public function index(): Response
    {
        $setupCheck = $this->buildMailSetupCheck();
        $mailboxes = Mailbox::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Mailbox $mailbox): array => $this->toMailboxListRow($mailbox, $setupCheck))
            ->all();

        return Inertia::render('ListEmails', [
            'mailboxes' => $mailboxes,
            'setupCheck' => $setupCheck,
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
        $setupCheck = $this->buildMailSetupCheck();
        $mailboxCheck = $this->evaluateMailboxAutoLogin($mailbox->toArray(), $setupCheck);
        if (! $mailboxCheck['ready']) {
            $email = (string) ($mailbox->email ?? 'mailbox');

            return redirect()
                ->route('emails.list')
                ->with('error', "Auto login blocked for {$email}: ".$mailboxCheck['message']);
        }

        $targetUrl = (string) ($setupCheck['webmail_url'] ?? $this->resolveWebmailUrl());

        return response()->view('webmail.autologin', [
            'targetUrl' => $targetUrl,
            'email' => (string) ($mailbox->email ?? ''),
            'password' => (string) ($mailbox->password ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $setupCheck
     * @return array<string, mixed>
     */
    private function toMailboxListRow(Mailbox $mailbox, array $setupCheck): array
    {
        $row = $mailbox->toArray();
        $autoLoginCheck = $this->evaluateMailboxAutoLogin($row, $setupCheck);
        unset($row['password']);
        $row['autologin_ready'] = $autoLoginCheck['ready'];
        $row['autologin_message'] = $autoLoginCheck['message'];

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMailSetupCheck(): array
    {
        $isWindows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS');
        $configuredWebmailEnv = trim((string) env('WEBMAIL_URL', ''));
        $webmailConfigured = $configuredWebmailEnv !== '';
        $webmailUrl = $this->resolveWebmailUrl();
        $webmailUrlValid = filter_var($webmailUrl, FILTER_VALIDATE_URL) !== false;
        $postfix = $this->serviceStatus('postfix');
        $dovecot = $this->serviceStatus('dovecot');
        $servicesReady = $isWindows || ($postfix === 'running' && $dovecot === 'running');
        $webmailReachable = $webmailUrlValid ? $this->isUrlReachable($webmailUrl) : false;
        $messages = [];

        if (! $webmailUrlValid) {
            $messages[] = 'WEBMAIL_URL is invalid. Configure a full URL (for example: http://server/roundcube/).';
        }
        if (! $webmailConfigured) {
            $messages[] = 'WEBMAIL_URL is not configured. Add it in .env to your real Roundcube URL.';
        }
        if (! $servicesReady) {
            $messages[] = 'Required services are down. Ensure Postfix and Dovecot are running.';
        }
        if ($webmailReachable === false) {
            $messages[] = 'Webmail endpoint is unreachable from panel server.';
        } elseif ($webmailReachable === null) {
            $messages[] = 'Webmail reachability check unavailable (curl extension missing).';
        }

        $autologinReady = $webmailConfigured && $webmailUrlValid && $servicesReady && $webmailReachable !== false;

        return [
            'is_windows' => $isWindows,
            'webmail_configured' => $webmailConfigured,
            'webmail_url' => $webmailUrl,
            'webmail_url_valid' => $webmailUrlValid,
            'webmail_reachable' => $webmailReachable,
            'services' => [
                'postfix' => $postfix,
                'dovecot' => $dovecot,
            ],
            'services_ready' => $servicesReady,
            'autologin_ready' => $autologinReady,
            'messages' => $messages,
        ];
    }

    private function resolveWebmailUrl(): string
    {
        return (string) env('WEBMAIL_URL', request()->getSchemeAndHttpHost().'/roundcube/');
    }

    private function serviceStatus(string $service): string
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return 'unknown';
        }

        $out = @shell_exec('systemctl is-active '.escapeshellarg($service).' 2>/dev/null');
        if (! is_string($out)) {
            return 'unknown';
        }

        return trim($out) === 'active' ? 'running' : 'down';
    }

    /**
     * Returns true when endpoint is reachable, false when explicitly unreachable,
     * and null when reachability check cannot run.
     */
    private function isUrlReachable(string $url): ?bool
    {
        if (! function_exists('curl_init')) {
            return null;
        }

        $ch = @curl_init($url);
        if ($ch === false) {
            return null;
        }

        @curl_setopt($ch, CURLOPT_NOBODY, true);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        @curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        @curl_exec($ch);
        $errno = (int) @curl_errno($ch);
        $code = (int) @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);

        if ($errno !== 0) {
            return false;
        }

        if ($code === 0) {
            return false;
        }

        return $code >= 200 && $code < 500;
    }

    /**
     * @param array<string, mixed> $mailbox
     * @param array<string, mixed> $setupCheck
     * @return array{ready: bool, message: string}
     */
    private function evaluateMailboxAutoLogin(array $mailbox, array $setupCheck): array
    {
        $email = trim((string) ($mailbox['email'] ?? ''));
        $password = trim((string) ($mailbox['password'] ?? ''));
        $status = strtolower(trim((string) ($mailbox['status'] ?? 'active')));
        if ($status !== '' && $status !== 'active') {
            return ['ready' => false, 'message' => 'Mailbox status is not active.'];
        }

        if ($email === '' || $password === '') {
            return ['ready' => false, 'message' => 'Mailbox credentials are incomplete.'];
        }

        if (! (bool) ($setupCheck['autologin_ready'] ?? false)) {
            $firstSetupMessage = (string) (($setupCheck['messages'][0] ?? '') ?: 'Mail setup check failed.');

            return ['ready' => false, 'message' => $firstSetupMessage];
        }

        return ['ready' => true, 'message' => 'Auto login is ready.'];
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
