<?php

namespace App\Services\Ssl;

use App\Models\Domain;
use App\Models\SslCertificate;
use App\Models\Website;
use App\Services\EdgeGatewayReloader;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\Website\WebsiteService;

class SslLifecycleService
{
    /** Suffixes that are never publicly resolvable, so a CA can never issue for them. */
    private const RESERVED_SSL_SUFFIXES = ['.localhost', '.local', '.test', '.example', '.invalid'];

    public function __construct(
        protected WebsiteService $websiteService,
        protected WildcardDnsTarget $wildcardDns,
    ) {
    }

    public static function isReservedSslDomain(string $domainName): bool
    {
        $domainName = strtolower(trim($domainName));
        if ($domainName === 'localhost') {
            return true;
        }
        foreach (self::RESERVED_SSL_SUFFIXES as $suffix) {
            if (str_ends_with($domainName, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function ensureForWebsite(Website $website, bool $force = false): array
    {
        $domainName = strtolower(trim((string) $website->domain));
        $domain = Domain::query()->firstOrNew(['name' => $domainName]);
        if (! $domain->exists) {
            $domain->id = (string) Str::uuid();
        }
        $domain->fill([
            'assigned_user_id' => $website->assigned_user_id,
            'assigned_reseller_id' => $website->assigned_reseller_id,
            'is_active' => true,
            'ssl_enabled' => (bool) $website->enable_ssl,
        ]);

        if (! $website->enable_ssl) {
            $domain->ssl_status = 'disabled';
            $domain->ssl_checked_at = now();
            $domain->save();
            return ['status' => 'disabled'];
        }

        if (self::isReservedSslDomain($domainName)) {
            $domain->ssl_status = 'ineligible';
            $domain->ssl_checked_at = now();
            $domain->save();
            throw new \RuntimeException(
                "SSL cannot be issued for \"{$domainName}\": it is not a publicly resolvable domain."
            );
        }

        // A name right under a wildcard site is already covered by its
        // certificate, which the gateway serves for it (backlog M4).
        if (! $force && ! $website->ssl_wildcard && ($covered = $this->coveredByWildcard($domainName)) !== null) {
            $this->recordValid($website, $domain, $covered);

            return $covered;
        }

        $payload = [
            'domain' => $domainName,
            'root_path' => $this->websiteService->resolveWebsiteDocumentRoot(
                (string) $website->root_path,
                (string) $website->start_directory,
            ),
            'renew_before_days' => max(0, (int) config('serverpanel.ssl_auto_renew_days', 30)),
            // Reinstall: issue a new certificate even when the current one is valid.
            'force' => $force,
        ];
        if ($website->ssl_wildcard) {
            // Resolved on every call, so a zone that moved is followed and a
            // Cloudflare token is never stored outside the panel's .env.
            $payload['wildcard'] = $this->wildcardDns->for($domainName);
        }
        $response = $this->drustSslRequest('ensure', $payload, $website->ssl_wildcard ? 600 : 300);
        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            $domain->ssl_status = 'failed';
            $domain->ssl_checked_at = now();
            $domain->save();
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'SSL ensure failed.'));
        }

        $this->recordValid($website, $domain, $data);

        return $data;
    }

    /**
     * Installs a certificate the customer brought. drust checks that it is
     * for this domain, has not expired and matches the key, and keeps it
     * apart from issued certificates; the key is never stored by the panel.
     *
     * @return array<string, mixed>
     */
    public function installCustomForWebsite(Website $website, string $certificate, string $privateKey): array
    {
        $domainName = strtolower(trim((string) $website->domain));
        if (self::isReservedSslDomain($domainName) || filter_var($domainName, FILTER_VALIDATE_IP) !== false) {
            throw new \RuntimeException("A certificate cannot be installed for \"{$domainName}\".");
        }

        $response = $this->drustSslRequest('install', [
            'domain' => $domainName,
            'certificate' => $certificate,
            'private_key' => $privateKey,
        ]);
        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            $message = (string) ($json['message'] ?? $response->body() ?: 'Certificate install failed.');
            throw new \RuntimeException(preg_replace('/^Failed: /', '', $message));
        }

        $website->forceFill(['enable_ssl' => true])->save();
        $domain = Domain::query()->firstOrNew(['name' => $domainName]);
        if (! $domain->exists) {
            $domain->id = (string) Str::uuid();
        }
        $domain->fill([
            'assigned_user_id' => $website->assigned_user_id,
            'assigned_reseller_id' => $website->assigned_reseller_id,
            'is_active' => true,
            'ssl_enabled' => true,
        ]);
        $this->recordValid($website, $domain, $data + ['custom' => true]);

        return $data;
    }

    /**
     * What a valid answer from drust means for the panel's own records.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordValid(Website $website, Domain $domain, array $data): void
    {
        $custom = ! empty($data['custom']);
        $expiresAt = ! empty($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : null;
        $domain->ssl_status = 'valid';
        $domain->ssl_expires_at = $expiresAt;
        $domain->ssl_checked_at = now();
        $domain->save();

        $certificate = SslCertificate::query()->firstOrNew(['domain' => $domain->name]);
        if (! $certificate->exists) {
            $certificate->id = (string) Str::uuid();
        }
        // A different certificate starts its expiry warnings over.
        $replaced = ! empty($data['issued']) || ! empty($data['renewed'])
            || (string) $certificate->type !== ($custom ? 'custom' : 'letsencrypt');
        $certificate->fill([
            'certificate_path' => $data['certificate_path'] ?? null,
            'private_key_path' => $data['private_key_path'] ?? null,
            'type' => $custom ? 'custom' : 'letsencrypt',
            'status' => 'valid',
            'issued_at' => ! empty($data['issued']) ? now() : $certificate->issued_at,
            'renewed_at' => ! empty($data['renewed']) ? now() : $certificate->renewed_at,
            'expires_at' => $expiresAt,
            // Nothing can renew a certificate the customer uploaded.
            'auto_renew' => ! $custom,
            'expiry_alert_days' => $replaced ? null : $certificate->expiry_alert_days,
        ])->save();

        $mode = $custom ? 'custom' : 'letsencrypt';
        if ((string) $website->ssl_mode !== $mode) {
            $website->forceFill(['ssl_mode' => $mode])->saveQuietly();
        }

        // Full reload so the gateway serves the certificate right away. A
        // per-domain reload (fired when enable_ssl is saved, before the cert
        // exists) is not enough on its own.
        app(EdgeGatewayReloader::class)->reload();
    }

    /**
     * Uninstall: drust deletes the certificate files, SSL is switched off and
     * the panel forgets the certificate.
     */
    public function removeForWebsite(Website $website): void
    {
        $domainName = strtolower(trim((string) $website->domain));
        $response = $this->drustSslRequest('remove', ['domain' => $domainName]);
        $json = $response->json();
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'SSL remove failed.'));
        }

        $website->forceFill(['enable_ssl' => false])->save();
        Domain::query()->where('name', $domainName)->update([
            'ssl_enabled' => false,
            'ssl_status' => 'disabled',
            'ssl_expires_at' => null,
            'ssl_checked_at' => now(),
        ]);
        SslCertificate::query()->where('domain', $domainName)->delete();

        app(EdgeGatewayReloader::class)->reload();
    }

    /**
     * The certificate of the wildcard site one label up, while it is good,
     * as drust would report it for this name.
     *
     * @return array<string, mixed>|null
     */
    private function coveredByWildcard(string $domainName): ?array
    {
        $parent = Str::after($domainName, '.');
        if ($parent === $domainName || ! str_contains($parent, '.')) {
            return null;
        }
        $covers = Website::query()->where('domain', $parent)->where('enable_ssl', true)->where('ssl_wildcard', true)->exists();
        if (! $covers) {
            return null;
        }
        $certificate = SslCertificate::query()->where('domain', $parent)->where('type', 'letsencrypt')->where('status', 'valid')->first();
        if ($certificate === null || $certificate->expires_at === null || Carbon::parse($certificate->expires_at)->isPast()) {
            return null;
        }

        return [
            'status' => 'valid',
            'issued' => false,
            'renewed' => false,
            'wildcard_of' => $parent,
            'certificate_path' => $certificate->certificate_path,
            'private_key_path' => $certificate->private_key_path,
            'expires_at' => Carbon::parse($certificate->expires_at)->toIso8601String(),
        ];
    }

    protected function drustSslRequest(string $action, array $payload, int $timeout = 300): \Illuminate\Http\Client\Response
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust SSL API is not configured.');
        }

        // A wildcard waits for its TXT record to reach the nameservers, twice.
        $request = Http::acceptJson()->asJson()->timeout($timeout);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request->post(rtrim($baseUrl, '/').'/api/v1/ssl/'.$action, $payload);
    }
}
