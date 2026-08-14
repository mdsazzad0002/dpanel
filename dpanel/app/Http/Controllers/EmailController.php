<?php

namespace App\Http\Controllers;

use App\Models\MailPlan;
use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\Website;
use App\Services\ResourceQuotaService;
use App\Services\ScriptExecutionGateway;
use App\Services\ScriptPathResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    private const DOVECOT_AUTH_FILE = '/etc/dovecot/conf.d/auth-serverpanel.conf.ext';
    private const DOVECOT_AUTH_INCLUDE_FILE = '/etc/dovecot/conf.d/10-auth.conf';

    public function __construct(private readonly ResourceQuotaService $quotas)
    {
    }

    public function webmailEntry(Request $request): RedirectResponse|HttpResponse
    {
        return redirect()->route('emails.list');
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

    public function dnsGuide(Request $request): Response
    {
        $domains = collect($this->readWebsiteDomains())
            ->merge(Mailbox::query()->pluck('domain')->all())
            ->filter(fn ($domain) => is_string($domain) && trim($domain) !== '')
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->unique()->sort()->values();

        $requestedDomain = strtolower(trim((string) $request->query('domain', '')));
        $domain = $domains->contains($requestedDomain) ? $requestedDomain : (string) ($domains->first() ?? '');
        $mailDomain = $domain !== '' ? MailDomain::query()->where('domain', $domain)->first() : null;
        $selector = trim((string) ($mailDomain?->dkim_selector ?: config('serverpanel.mail.dkim_selector', 'default'))) ?: 'default';
        $dkimDomain = trim((string) config('serverpanel.mail.dkim_domain', ''));
        $dkimPublicKey = preg_replace('/\s+/', '', trim((string) ($mailDomain?->dkim_public_key ?: config('serverpanel.mail.dkim_public_key', '')))) ?: '';
        $serverIp = trim((string) config('serverpanel.mail.server_ip', ''));
        $mailHost = $domain !== '' ? 'mail.'.$domain : '';
        $dkimReady = ($dkimDomain === '' || $dkimDomain === $domain) && $dkimPublicKey !== '';

        $records = $domain === '' ? [] : [
            ['type' => 'A', 'name' => 'mail', 'value' => $serverIp, 'priority' => null, 'purpose' => 'Mail server hostname'],
            ['type' => 'MX', 'name' => '@', 'value' => $mailHost, 'priority' => 10, 'purpose' => 'Receive email for '.$domain],
            ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 mx a:'.$mailHost.' ~all', 'priority' => null, 'purpose' => 'Authorize this server to send email'],
            ['type' => 'TXT', 'name' => $selector.'._domainkey', 'value' => $dkimReady ? 'v=DKIM1; k=rsa; p='.$dkimPublicKey : '', 'priority' => null, 'purpose' => 'DKIM signature verification'],
            ['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@'.$domain.'; adkim=s; aspf=s', 'priority' => null, 'purpose' => 'Monitor SPF/DKIM alignment'],
        ];

        return Inertia::render('EmailDnsGuide', [
            'domains' => $domains->all(),
            'selectedDomain' => $domain,
            'mailHost' => $mailHost,
            'serverIp' => $serverIp,
            'dkimReady' => $dkimReady,
            'dkimConfiguredDomain' => $dkimDomain,
            'records' => $records,
        ]);
    }

    public function generateDkim(Request $request, ScriptExecutionGateway $gateway): RedirectResponse
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
        ]);
        $domain = strtolower(trim($validated['domain']));
        abort_unless(in_array($domain, $this->readWebsiteDomains(), true) || Mailbox::query()->where('domain', $domain)->exists(), 403);
        $selector = trim((string) config('serverpanel.mail.dkim_selector', 'default')) ?: 'default';
        $script = ScriptPathResolver::resolveRepositoryRoot().'/scripts/generate-dkim.sh';
        $result = $gateway->execute($script, [$domain, $selector], [], true);

        if (! $result['success'] || ! preg_match('/^DKIM_PUBLIC_KEY=(.+)$/m', $result['output'], $match)) {
            return redirect()->route('emails.guide', ['domain' => $domain])
                ->with('error', trim($result['output']) ?: 'DKIM key generation failed.');
        }

        MailDomain::query()->updateOrCreate(['domain' => $domain], [
            'enable_dkim' => true,
            'dkim_selector' => $selector,
            'dkim_public_key' => trim($match[1]),
            'status' => 'active',
        ]);

        return redirect()->route('emails.guide', ['domain' => $domain])
            ->with('success', "DKIM key generated and signing enabled for {$domain}.");
    }

    public function exportDnsZone(string $token, string $domain): HttpResponse
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));
        abort_unless(
            in_array($domain, $this->readWebsiteDomains(), true) || Mailbox::query()->where('domain', $domain)->exists(),
            403
        );

        $mailDomain = MailDomain::query()->where('domain', $domain)->first();
        $selector = trim((string) ($mailDomain?->dkim_selector ?: config('serverpanel.mail.dkim_selector', 'default'))) ?: 'default';
        $configuredDkimDomain = trim((string) config('serverpanel.mail.dkim_domain', ''));
        $publicKey = preg_replace('/\s+/', '', trim((string) ($mailDomain?->dkim_public_key ?: config('serverpanel.mail.dkim_public_key', '')))) ?: '';
        $dkimReady = ($configuredDkimDomain === '' || $configuredDkimDomain === $domain) && $publicKey !== '';
        $serverIp = trim((string) config('serverpanel.mail.server_ip', ''));
        $mailHost = 'mail.'.$domain.'.';
        $lines = [
            '; Cloudflare BIND zone import generated by dPanel',
            '; Domain: '.$domain,
            '; Import in Cloudflare: DNS > Records > Import and Export > Import DNS records',
            '$ORIGIN '.$domain.'.',
            '$TTL 3600',
            '',
        ];

        if (filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $lines[] = 'mail 3600 IN A '.$serverIp;
        } else {
            $lines[] = '; A record omitted: SERVERPANEL_MAIL_SERVER_IP is not configured with a public IPv4 address.';
        }

        $lines[] = '@ 3600 IN MX 10 '.$mailHost;
        $lines[] = '@ 3600 IN TXT '.$this->bindTxtValue('v=spf1 mx a:mail.'.$domain.' ~all');
        if ($dkimReady) {
            $lines[] = $selector.'._domainkey 3600 IN TXT '.$this->bindTxtValue('v=DKIM1; k=rsa; p='.$publicKey);
        } else {
            $lines[] = '; DKIM record omitted: generate a DKIM key in the Mail DNS Guide first.';
        }
        $lines[] = '_dmarc 3600 IN TXT '.$this->bindTxtValue('v=DMARC1; p=none; rua=mailto:postmaster@'.$domain.'; adkim=s; aspf=s');
        $lines[] = '';
        $lines[] = '; Keep the mail A record DNS-only (not proxied) after importing.';

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$domain.'-mail-dns.txt"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function bindTxtValue(string $value): string
    {
        return collect(str_split($value, 200))
            ->map(fn (string $chunk): string => '"'.addcslashes($chunk, "\\\"").'"')
            ->implode(' ');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $domain = strtolower(trim((string) $validated['domain']));
        $mailbox = strtolower(trim((string) $validated['mailbox']));
        $email = "{$mailbox}@{$domain}";
        $password = (string) $validated['password'];

        if ($owner = $this->quotas->ownerForDomain($domain)) {
            $this->quotas->assertMailboxAllowed($owner, (int) $validated['quota_mb'], ! empty($validated['forwarding_to']));
        }

        if (Mailbox::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json([
                'ok' => false,
                'message' => "Mailbox {$email} already exists.",
            ], 422);
        }

        try {
            $storage = $this->storageAttributes($domain, $mailbox);
            $mailboxRecord = DB::transaction(fn () => Mailbox::query()->create([
                'id' => (string) str()->uuid(),
                'domain' => $domain,
                'mailbox' => $mailbox,
                'email' => $email,
                'password' => $this->hashStoragePassword($password),
                'client_password' => $password,
                'quota_mb' => (int) $validated['quota_mb'],
                'forwarding_to' => trim((string) ($validated['forwarding_to'] ?? '')),
                'status' => 'active',
                ...$storage,
                'plan_id' => $owner?->package_id,
            ]));

            $storageSync = $this->provisionMailboxStorage($mailboxRecord);
            if (! $storageSync['ok']) {
                $mailboxRecord->delete();
                throw new \RuntimeException($storageSync['message']);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => "Mailbox {$email} was not created: ".$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => "Mailbox {$email} created and synced to storage server.",
            'mailbox' => [
                'email' => $email,
                'domain' => $domain,
                'mailbox' => $mailbox,
            ],
        ], 201);
    }

    public function edit(string $token, string $id): Response
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        return Inertia::render('EditEmail', [
            'mailbox' => array_merge($mailbox->toArray(), ['password' => '']),
            'websiteDomains' => $this->readWebsiteDomains(),
        ]);
    }

    public function update(Request $request,string $token,  string $id): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $domain = strtolower(trim((string) $validated['domain']));
        $mailboxName = strtolower(trim((string) $validated['mailbox']));
        $email = "{$mailboxName}@{$domain}";
        $password = (string) $validated['password'];

        $mailboxRecord = Mailbox::query()->find($id);
        if ($mailboxRecord === null) {
            return redirect()->route('emails.list')->with('error', 'Mailbox not found.');
        }

        if ($owner = $this->quotas->ownerForDomain($domain)) {
            $this->quotas->assertMailboxAllowed($owner, (int) $validated['quota_mb'], ! empty($validated['forwarding_to']), $mailboxRecord->id);
        }

        $exists = Mailbox::query()
            ->where('id', '!=', $id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($exists) {
            return redirect()->route('emails.edit', $id)->with('error', "Mailbox {$email} already exists.");
        }

        $oldMailHome = (string) ($mailboxRecord->mail_home ?? '');
        $storage = $this->storageAttributes($domain, $mailboxName);
        $mailboxRecord->fill([
            'domain' => $domain,
            'mailbox' => $mailboxName,
            'email' => $email,
            'password' => $this->hashStoragePassword($password),
            'client_password' => $password,
            'quota_mb' => (int) $validated['quota_mb'],
            'forwarding_to' => trim((string) ($validated['forwarding_to'] ?? '')),
            'plan_id' => $owner?->package_id,
            ...$storage,
        ]);
        $mailboxRecord->save();

        $storageSync = $this->provisionMailboxStorage($mailboxRecord, $oldMailHome);
        if (! $storageSync['ok']) {
            return redirect()->route('emails.edit', $id)->with('error', "Mailbox {$email} was updated in database, but storage sync failed: ".$storageSync['message']);
        }

        return redirect()->route('emails.list')->with('success', "Mailbox {$email} updated and synced to storage server.");
    }

    public function destroy(string $token, string $id): RedirectResponse
    {
        $mailbox = Mailbox::query()->find($id);
        if ($mailbox === null) {
            return redirect()->route('emails.list')->with('error', 'Mailbox not found.');
        }

        $email = strtolower(trim((string) ($mailbox->email ?? '')));
        $storageDelete = $email !== '' ? $this->removeMailboxFromStorage($mailbox) : ['ok' => true, 'message' => ''];

        $deleted = Mailbox::query()->where('id', $id)->delete();
        if ($deleted === 0) {
            return redirect()->route('emails.list')->with('error', 'Mailbox not found.');
        }

        if (! $storageDelete['ok']) {
            return redirect()
                ->route('emails.list')
                ->with('error', "Mailbox deleted from panel, but storage cleanup failed for {$email}: ".$storageDelete['message']);
        }

        return redirect()->route('emails.list')->with('success', 'Mailbox deleted successfully.');
    }

    public function login(string $token, string $id)
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

        $targetUrl = $this->buildRoundcubeLoginUrl(
            (string) ($setupCheck['webmail_url'] ?? $this->resolveWebmailUrl())
        );
        if ($targetUrl === '') {
            return redirect()
                ->route('emails.list')
                ->with('error', 'Webmail URL is not configured. Set WEBMAIL_URL to your Roundcube endpoint.');
        }

        $token = $this->issueWebmailSsoToken(
            (string) ($mailbox->email ?? ''),
            (string) ($mailbox->client_password ?? ''),
            600
        );

        if ($token !== '') {
            $sep = str_contains($targetUrl, '?') ? '&' : '?';

            return redirect()->away($targetUrl.$sep.'sso_token='.rawurlencode($token));
        }

        return response()->view('webmail.autologin', [
            'targetUrl' => $targetUrl,
            'email' => (string) ($mailbox->email ?? ''),
            'password' => (string) ($mailbox->client_password ?? ''),
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

        if (! empty($row['plan_id'])) {
            $plan = MailPlan::query()->find($row['plan_id']);
            $row['plan'] = $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'max_storage_mb' => $plan->max_storage_mb,
            ] : null;
        } else {
            $row['plan'] = null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMailSetupCheck(): array
    {
        $isWindows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS');
        $configuredWebmailEnv = trim((string) config('app.webmail_url', ''));
        $webmailConfigured = true;
        $shouldProbeWebmail = $configuredWebmailEnv !== ''
            && strcasecmp($configuredWebmailEnv, 'auto') !== 0
            && filter_var($configuredWebmailEnv, FILTER_VALIDATE_URL) !== false;
        $webmailUrl = $this->resolveWebmailUrl();
        $webmailLoginUrl = $this->buildRoundcubeLoginUrl($webmailUrl);
        $webmailUrlValid = filter_var($webmailUrl, FILTER_VALIDATE_URL) !== false;
        $postfix = $this->serviceStatus('postfix');
        $dovecot = $this->serviceStatus('dovecot');
        $opendkim = $this->serviceStatus('opendkim');
        $rspamd = $this->serviceStatus('rspamd');
        $storageBackendReady = $isWindows || $this->isDovecotStorageBackendReady();
        $dovecotMysqlReady = $isWindows || $this->isDovecotMysqlDriverAvailable();
        $servicesReady = $isWindows || $dovecot === 'running';
        $dkimPublicKey = trim((string) config('serverpanel.mail.dkim_public_key', ''));
        $dkimServiceReady = $isWindows || $opendkim === 'running' || $rspamd === 'running';
        $dkimReady = $dkimServiceReady || $dkimPublicKey !== '';
        $webmailReachable = $shouldProbeWebmail && $webmailUrlValid ? $this->isUrlReachable($webmailUrl) : null;
        $messages = [];

        if (! $webmailUrlValid) {
            $messages[] = 'Mail client URL is invalid. Set WEBMAIL_URL to a full URL or use WEBMAIL_URL=auto.';
        }
        if (! $shouldProbeWebmail && $configuredWebmailEnv !== '') {
            $messages[] = 'WEBMAIL_URL value is invalid. Use a full URL or set WEBMAIL_URL=auto.';
        }
        if (! $servicesReady) {
            $messages[] = 'Dovecot is down. Start Dovecot to enable mailbox access.';
        }
        if (! $storageBackendReady) {
            $messages[] = 'Dovecot mailbox backend is not ready. Sync mailbox storage first.';
        }
        if (! $isWindows && ! $dovecotMysqlReady) {
            $messages[] = 'dovecot-mysql driver is not detected. Install package: dovecot-mysql.';
        }
        if (! $isWindows && $postfix !== 'running') {
            $messages[] = 'Postfix is down. Sending mail may fail even if login works.';
        }
        if (! $isWindows && ! $dkimReady) {
            $messages[] = 'DKIM signing is not configured. Install OpenDKIM or Rspamd and publish the DKIM TXT record.';
        }
        if ($webmailReachable === false) {
            $messages[] = 'Mail client endpoint is unreachable from panel server.';
        } elseif ($shouldProbeWebmail && $webmailReachable === null) {
            $messages[] = 'Mail client reachability check unavailable (curl extension missing).';
        }

        $autologinReady = $webmailConfigured
            && $webmailUrlValid
            && $servicesReady
            && $storageBackendReady
            && $webmailReachable !== false;

        return [
            'is_windows' => $isWindows,
            'webmail_configured' => $webmailConfigured,
            'webmail_url' => $webmailUrl,
            'webmail_login_url' => $webmailLoginUrl,
            'webmail_url_valid' => $webmailUrlValid,
            'webmail_reachable' => $webmailReachable,
            'services' => [
                'postfix' => $postfix,
                'dovecot' => $dovecot,
                'opendkim' => $opendkim,
                'rspamd' => $rspamd,
            ],
            'services_ready' => $servicesReady,
            'dkim_ready' => $dkimReady,
            'storage_backend_ready' => $storageBackendReady,
            'dovecot_mysql_ready' => $dovecotMysqlReady,
            'autologin_ready' => $autologinReady,
            'messages' => $messages,
        ];
    }

    private function resolveWebmailUrl(?Request $request = null): string
    {
        $request = $request ?? request();
        $configured = trim((string) config('app.webmail_url', 'auto'));
        if ($configured !== '' && strcasecmp($configured, 'auto') !== 0) {
            return $configured;
        }

        return $this->buildPanelRoundcubeUrl($request);
    }

    private function buildPanelRoundcubeUrl(Request $request): string
    {
        $scheme = $request->getScheme();
        $host = trim((string) $request->getHost());

        $pathPrefix = '';
        $firstSegment = $request->segment(1);
        if ($firstSegment !== null && str_starts_with($firstSegment, 'cpsess') && preg_match('/^cpsess[0-9a-fA-F]{64}$/', $firstSegment)) {
            $pathPrefix = '/'.$firstSegment;
        }

        return sprintf('%s://%s%s/roundcube/', $scheme, $host, $pathPrefix);
    }

    private function buildRoundcubeLoginUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return $baseUrl;
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $baseUrl;
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }
        $query['_task'] = 'login';

        $authority = '';
        if (isset($parts['user'])) {
            $authority .= $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':'.$parts['pass'];
            }
            $authority .= '@';
        }
        $authority .= $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        $url = $parts['scheme'].'://'.$authority.$path;
        $url .= '?'.http_build_query($query);

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }

    private function issueWebmailSsoToken(string $email, string $password, int $ttlSeconds = 600): string
    {
        $email = trim($email);
        $password = (string) $password;
        if ($email === '' || $password === '') {
            return '';
        }

        $ttlSeconds = max(60, min(1800, $ttlSeconds));
        $token = bin2hex(random_bytes(32));
        Cache::put(
            $this->webmailSsoCacheKey($token),
            ['email' => $email, 'password' => $password],
            now()->addSeconds($ttlSeconds)
        );

        return $token;
    }

    private function webmailSsoCacheKey(string $token): string
    {
        return 'serverpanel:webmail_sso:'.$token;
    }

    private function normalizePort(int $value, int $fallback): int
    {
        return $value >= 1 && $value <= 65535 ? $value : $fallback;
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

        $storageCheck = $this->checkMailboxStorageSync($email);
        if (! $storageCheck['ok']) {
            return ['ready' => false, 'message' => $storageCheck['message']];
        }

        return ['ready' => true, 'message' => 'Mailbox client is ready. User record is created on demand.'];
    }

    private function isDovecotStorageBackendReady(): bool
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return true;
        }

        return is_file(self::DOVECOT_AUTH_FILE)
            && is_file('/etc/dovecot/dpanel-sql.conf.ext')
            && is_file(self::DOVECOT_AUTH_INCLUDE_FILE);
    }

    private function isDovecotMysqlDriverAvailable(): bool
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return true;
        }

        $paths = [
            '/usr/lib/dovecot/modules/auth/libdriver_mysql.so',
            '/usr/lib/dovecot/modules/auth/libauthdb_mysql.so',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        $dpkgOutput = trim((string) @shell_exec("dpkg -l 2>/dev/null | grep -E '^ii\\s+dovecot-mysql\\s'"));
        if ($dpkgOutput !== '') {
            return true;
        }

        return false;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkMailboxStorageSync(string $email): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ok' => true, 'message' => ''];
        }

        $mailbox = Mailbox::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
        if ($mailbox === null || $mailbox->status !== 'active') {
            return ['ok' => false, 'message' => 'Mailbox email is empty.'];
        }
        return is_dir((string) $mailbox->mail_home)
            ? ['ok' => true, 'message' => '']
            : ['ok' => false, 'message' => 'Mailbox Maildir is missing.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function provisionMailboxStorage(Mailbox $mailbox, ?string $oldMailHome = null): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ok' => true, 'message' => 'Storage sync skipped on Windows.'];
        }

        return $this->syncMailboxStorage($mailbox, $oldMailHome !== null && $oldMailHome !== '' && $oldMailHome !== (string) $mailbox->mail_home ? 'move' : 'create', $oldMailHome);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function removeMailboxFromStorage(Mailbox $mailbox): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ok' => true, 'message' => 'Storage cleanup skipped on Windows.'];
        }

        return $this->syncMailboxStorage($mailbox, 'remove');
    }

    /** @return array{ok: bool, message: string} */
    private function syncMailboxStorage(Mailbox $mailbox, string $action, ?string $previousMailHome = null): array
    {
        $baseUrl = rtrim((string) config('serverpanel.execution_api_base_url', ''), '/');
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $token === '') return ['ok' => false, 'message' => 'Rust execution service is not configured.'];
        try {
            $response = Http::acceptJson()->asJson()->withToken($token)->timeout((int) config('serverpanel.execution_api_timeout', 60))->post($baseUrl.'/api/v1/mailbox-storage', [
                'action' => $action, 'mail_home' => $mailbox->mail_home, 'site_owner' => $mailbox->site_owner, 'previous_mail_home' => $previousMailHome,
            ]);
            return $response->ok() && $response->json('success') === true
                ? ['ok' => true, 'message' => 'Maildir synchronized.']
                : ['ok' => false, 'message' => (string) ($response->json('message') ?: $response->body())];
        } catch (\Throwable $e) { return ['ok' => false, 'message' => $e->getMessage()]; }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function ensureDovecotStorageBackendReady(): array
    {
        if (! is_dir('/etc/dovecot/conf.d')) {
            return [
                'ok' => false,
                'message' => 'Dovecot is not installed. Install/start Dovecot first (dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql).',
            ];
        }

        if (! is_file(self::DOVECOT_AUTH_FILE) || ! is_file('/etc/dovecot/dpanel-sql.conf.ext')) {
            return ['ok' => false, 'message' => 'Dovecot SQL auth is not configured. Run dpanel mail installation/migration.'];
        }

        return ['ok' => true, 'message' => 'Dovecot SQL backend is ready.'];
    }

    private function hashStoragePassword(string $password): string
    {
        $hash = trim((string) @shell_exec('doveadm pw -s SHA512-CRYPT -p '.escapeshellarg($password).' 2>/dev/null'));
        if ($hash !== '') {
            return $hash;
        }

        $hash = trim((string) @shell_exec('openssl passwd -6 '.escapeshellarg($password).' 2>/dev/null'));
        if ($hash !== '') {
            return $hash;
        }

        if (str_contains($password, ':')) {
            return '';
        }

        return '{PLAIN}'.$password;
    }

    private function resolveSystemId(string $mode, string $account, int $fallback): int
    {
        $value = trim((string) @shell_exec('id -'.$mode.' '.escapeshellarg($account).' 2>/dev/null'));
        if ($value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return $fallback;
    }

    /** @return array{site_owner: string, mail_home: string, mail_uid: int, mail_gid: int} */
    private function storageAttributes(string $domain, string $mailbox): array
    {
        $website = Website::query()->whereRaw('LOWER(domain) = ?', [$domain])->first();
        $owner = trim((string) ($website?->site_owner ?? ''));
        if ($owner === '' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $owner)) {
            throw new \RuntimeException("No valid website owner found for {$domain}.");
        }
        $uid = $this->resolveSystemId('u', $owner, -1);
        $gid = $this->resolveSystemId('g', $owner, -1);
        if ($uid < 0 || $gid < 0) {
            throw new \RuntimeException("System account {$owner} does not exist.");
        }
        $safeDomain = strtolower($domain);
        $safeMailbox = strtolower($mailbox);
        return [
            'site_owner' => $owner,
            'mail_home' => "/home/{$owner}/mail/{$safeDomain}/{$safeMailbox}/Maildir",
            'mail_uid' => $uid,
            'mail_gid' => $gid,
        ];
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

    /**
     * @return array<int, string>
     */
    private function readMailboxDomains(): array
    {
        try {
            return Mailbox::query()
                ->pluck('domain')
                ->filter(fn ($domain) => is_string($domain) && trim($domain) !== '')
                ->map(fn ($domain) => strtolower(trim((string) $domain)))
                ->unique()
                ->sort()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int, array{id: string, name: string, slug: string, max_storage_mb: int, max_mailboxes: int}>
     */
    private function readPlans(): array
    {
        try {
            return MailPlan::query()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'slug', 'max_storage_mb', 'max_mailboxes'])
                ->map(fn (MailPlan $plan): array => $plan->toArray())
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
