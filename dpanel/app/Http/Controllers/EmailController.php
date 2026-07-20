<?php

namespace App\Http\Controllers;

use App\Models\MailPlan;
use App\Models\Mailbox;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    private const DOVECOT_USERS_FILE = '/etc/dovecot/serverpanel-users';
    private const DOVECOT_AUTH_FILE = '/etc/dovecot/conf.d/auth-serverpanel.conf.ext';
    private const DOVECOT_AUTH_INCLUDE_FILE = '/etc/dovecot/conf.d/10-auth.conf';
    private const VMAIL_BASE_DIR = '/var/mail/vhosts';
    private const VMAIL_USER = 'vmail';
    private const VMAIL_GROUP = 'vmail';

    public function webmailEntry(Request $request): RedirectResponse|HttpResponse
    {
        return redirect()->route('emails.list');
    }

    public function create(): Response
    {
        return Inertia::render('CreateEmail', [
            'websiteDomains' => $this->readWebsiteDomains(),
            'plans' => $this->readPlans(),
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
        $password = (string) $validated['password'];

        if (Mailbox::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return redirect()->route('emails.create')->with('error', "Mailbox {$email} already exists.");
        }

        $storageSync = $this->provisionMailboxStorage($email, $password);
        if (! $storageSync['ok']) {
            return redirect()
                ->route('emails.create')
                ->with('error', "Mailbox {$email} was not created: ".$storageSync['message']);
        }

        try {
            Mailbox::query()->create([
                'id' => (string) str()->uuid(),
                'domain' => $domain,
                'mailbox' => $mailbox,
                'email' => $email,
                'password' => $password,
                'quota_mb' => (int) $validated['quota_mb'],
                'forwarding_to' => trim((string) ($validated['forwarding_to'] ?? '')),
                'status' => 'active',
                'plan_id' => ! empty($validated['plan_id']) ? $validated['plan_id'] : null,
            ]);
        } catch (\Throwable $e) {
            $this->removeMailboxFromStorage($email);

            return redirect()
                ->route('emails.create')
                ->with('error', "Mailbox {$email} was not created: ".$e->getMessage());
        }

        return redirect()->route('emails.list')->with('success', "Mailbox {$email} created and synced to storage server.");
    }

    public function edit(string $token, string $id): Response
    {
        $mailbox = Mailbox::query()->find($id);
        abort_if($mailbox === null, 404);

        return Inertia::render('EditEmail', [
            'mailbox' => $mailbox->toArray(),
            'websiteDomains' => $this->readWebsiteDomains(),
            'plans' => $this->readPlans(),
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

        $exists = Mailbox::query()
            ->where('id', '!=', $id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($exists) {
            return redirect()->route('emails.edit', $id)->with('error', "Mailbox {$email} already exists.");
        }

        $oldEmail = strtolower(trim((string) ($mailboxRecord->email ?? '')));
        $storageSync = $this->provisionMailboxStorage($email, $password, $oldEmail !== '' ? $oldEmail : null);
        if (! $storageSync['ok']) {
            return redirect()
                ->route('emails.edit', $id)
                ->with('error', "Mailbox {$email} was not updated: ".$storageSync['message']);
        }

        $mailboxRecord->fill([
            'domain' => $domain,
            'mailbox' => $mailboxName,
            'email' => $email,
            'password' => $password,
            'quota_mb' => (int) $validated['quota_mb'],
            'forwarding_to' => trim((string) ($validated['forwarding_to'] ?? '')),
            'plan_id' => ! empty($validated['plan_id']) ? $validated['plan_id'] : null,
        ]);
        $mailboxRecord->save();

        return redirect()->route('emails.list')->with('success', "Mailbox {$email} updated and synced to storage server.");
    }

    public function destroy(string $token, string $id): RedirectResponse
    {
        $mailbox = Mailbox::query()->find($id);
        if ($mailbox === null) {
            return redirect()->route('emails.list')->with('error', 'Mailbox not found.');
        }

        $email = strtolower(trim((string) ($mailbox->email ?? '')));
        $storageDelete = $email !== '' ? $this->removeMailboxFromStorage($email) : ['ok' => true, 'message' => ''];

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
            (string) ($mailbox->password ?? ''),
            600
        );

        if ($token !== '') {
            $sep = str_contains($targetUrl, '?') ? '&' : '?';

            return redirect()->away($targetUrl.$sep.'sso_token='.rawurlencode($token));
        }

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

        return is_file(self::DOVECOT_USERS_FILE)
            && is_file(self::DOVECOT_AUTH_FILE)
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

        if (! is_file(self::DOVECOT_USERS_FILE)) {
            return ['ok' => false, 'message' => 'Dovecot users file is missing. Recreate mailbox or run mailbox sync.'];
        }

        $target = strtolower(trim($email));
        if ($target === '') {
            return ['ok' => false, 'message' => 'Mailbox email is empty.'];
        }

        $lines = $this->readDovecotUserLines();
        foreach ($lines as $line) {
            $lineEmail = strtolower(trim((string) strtok($line, ':')));
            if ($lineEmail === $target) {
                return ['ok' => true, 'message' => ''];
            }
        }

        return ['ok' => false, 'message' => 'Mailbox is not synced to Dovecot auth users file.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function provisionMailboxStorage(string $email, string $password, ?string $oldEmail = null): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ok' => true, 'message' => 'Storage sync skipped on Windows.'];
        }

        $backendReady = $this->ensureDovecotStorageBackendReady();
        if (! $backendReady['ok']) {
            return $backendReady;
        }

        $hash = $this->hashStoragePassword($password);
        if ($hash === '') {
            return ['ok' => false, 'message' => 'Unable to hash mailbox password for Dovecot.'];
        }

        $mailDir = $this->maildirPathForEmail($email);
        if ($mailDir === '') {
            return ['ok' => false, 'message' => 'Invalid mailbox email format for storage path.'];
        }

        $uid = $this->resolveSystemId('u', self::VMAIL_USER, 5000);
        $gid = $this->resolveSystemId('g', self::VMAIL_GROUP, 5000);
        $entry = sprintf(
            '%s:%s:%d:%d::%s::userdb_mail=maildir:%s',
            $email,
            $hash,
            $uid,
            $gid,
            $mailDir,
            $mailDir,
        );

        $existingLines = $this->readDovecotUserLines();
        $targetEmail = strtolower(trim($email));
        $oldTarget = strtolower(trim((string) $oldEmail));
        $nextLines = [];

        foreach ($existingLines as $line) {
            $lineEmail = strtolower(trim((string) strtok($line, ':')));
            if ($lineEmail === '' || $lineEmail === $targetEmail || ($oldTarget !== '' && $lineEmail === $oldTarget)) {
                continue;
            }
            $nextLines[] = $line;
        }

        $nextLines[] = $entry;
        sort($nextLines, SORT_NATURAL | SORT_FLAG_CASE);

        if (! $this->writeDovecotUserLines($nextLines)) {
            return ['ok' => false, 'message' => 'Failed to write Dovecot users file.'];
        }

        @mkdir($mailDir.'/cur', 0770, true);
        @mkdir($mailDir.'/new', 0770, true);
        @mkdir($mailDir.'/tmp', 0770, true);
        @shell_exec('chown -R '.escapeshellarg(self::VMAIL_USER.':'.self::VMAIL_GROUP).' '.escapeshellarg($mailDir).' 2>/dev/null');
        @shell_exec('chmod -R 0770 '.escapeshellarg($mailDir).' 2>/dev/null');

        if (! $this->restartService('dovecot')) {
            return ['ok' => false, 'message' => 'Dovecot restart failed after mailbox sync.'];
        }

        return ['ok' => true, 'message' => 'Storage server synchronized.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function removeMailboxFromStorage(string $email): array
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return ['ok' => true, 'message' => 'Storage cleanup skipped on Windows.'];
        }

        if (! is_file(self::DOVECOT_USERS_FILE)) {
            return ['ok' => true, 'message' => 'Dovecot users file not found.'];
        }

        $targetEmail = strtolower(trim($email));
        $existingLines = $this->readDovecotUserLines();
        $nextLines = [];
        foreach ($existingLines as $line) {
            $lineEmail = strtolower(trim((string) strtok($line, ':')));
            if ($lineEmail === '' || $lineEmail === $targetEmail) {
                continue;
            }
            $nextLines[] = $line;
        }

        if (! $this->writeDovecotUserLines($nextLines)) {
            return ['ok' => false, 'message' => 'Failed to update Dovecot users file during cleanup.'];
        }

        if (! $this->restartService('dovecot')) {
            return ['ok' => false, 'message' => 'Dovecot restart failed after mailbox cleanup.'];
        }

        return ['ok' => true, 'message' => 'Storage cleanup completed.'];
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

        @shell_exec('getent group '.escapeshellarg(self::VMAIL_GROUP).' >/dev/null 2>&1 || groupadd --system '.escapeshellarg(self::VMAIL_GROUP));
        @shell_exec('id -u '.escapeshellarg(self::VMAIL_USER).' >/dev/null 2>&1 || useradd --system --gid '.escapeshellarg(self::VMAIL_GROUP).' --home '.escapeshellarg(self::VMAIL_BASE_DIR).' --shell /usr/sbin/nologin '.escapeshellarg(self::VMAIL_USER));

        @mkdir(self::VMAIL_BASE_DIR, 0770, true);
        if (! is_file(self::DOVECOT_USERS_FILE)) {
            @touch(self::DOVECOT_USERS_FILE);
        }
        @chmod(self::DOVECOT_USERS_FILE, 0640);

        $authConfig = <<<'CFG'
passdb {
  driver = passwd-file
  args = scheme=SHA512-CRYPT username_format=%u /etc/dovecot/serverpanel-users
}

userdb {
  driver = static
  args = uid=vmail gid=vmail home=/var/mail/vhosts/%d/%n mail=maildir:/var/mail/vhosts/%d/%n
}
CFG;

        if (@file_put_contents(self::DOVECOT_AUTH_FILE, $authConfig.PHP_EOL) === false) {
            return ['ok' => false, 'message' => 'Unable to write Dovecot auth config for panel mailboxes.'];
        }
        @chmod(self::DOVECOT_AUTH_FILE, 0644);

        $includeContents = @file_get_contents(self::DOVECOT_AUTH_INCLUDE_FILE);
        if (! is_string($includeContents)) {
            return ['ok' => false, 'message' => 'Unable to read Dovecot auth include file.'];
        }
        if (! str_contains($includeContents, 'auth-serverpanel.conf.ext')) {
            if (@file_put_contents(self::DOVECOT_AUTH_INCLUDE_FILE, PHP_EOL.'!include auth-serverpanel.conf.ext'.PHP_EOL, FILE_APPEND) === false) {
                return ['ok' => false, 'message' => 'Unable to append panel auth include to Dovecot config.'];
            }
        }

        return ['ok' => true, 'message' => 'Dovecot backend is ready.'];
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

    private function maildirPathForEmail(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '';
        }

        return rtrim(self::VMAIL_BASE_DIR, '/').'/'.$parts[1].'/'.$parts[0];
    }

    /**
     * @return array<int, string>
     */
    private function readDovecotUserLines(): array
    {
        $raw = @file(self::DOVECOT_USERS_FILE, FILE_IGNORE_NEW_LINES);
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($line): string => trim((string) $line), $raw), static function (string $line): bool {
            return $line !== '' && ! str_starts_with($line, '#');
        }));
    }

    /**
     * @param array<int, string> $lines
     */
    private function writeDovecotUserLines(array $lines): bool
    {
        $payload = count($lines) > 0 ? implode(PHP_EOL, $lines).PHP_EOL : '';
        $written = @file_put_contents(self::DOVECOT_USERS_FILE, $payload, LOCK_EX);
        if ($written === false) {
            return false;
        }

        @chmod(self::DOVECOT_USERS_FILE, 0640);

        return true;
    }

    private function restartService(string $service): bool
    {
        if (str_starts_with(strtoupper(PHP_OS_FAMILY), 'WINDOWS')) {
            return true;
        }

        $exists = trim((string) @shell_exec('systemctl cat '.escapeshellarg($service).'.service 2>/dev/null'));
        if ($exists === '') {
            return false;
        }

        $out = [];
        $exitCode = 1;
        @exec('systemctl restart '.escapeshellarg($service).' 2>&1', $out, $exitCode);

        return $exitCode === 0;
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
            'plan_id' => ['nullable', 'string', 'exists:mail_plans,id'],
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
