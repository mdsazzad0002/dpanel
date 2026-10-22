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

    public function __construct(protected WebsiteService $websiteService)
    {
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

        $response = $this->drustSslRequest('ensure', [
            'domain' => $domainName,
            'root_path' => $this->websiteService->resolveWebsiteDocumentRoot(
                (string) $website->root_path,
                (string) $website->start_directory,
            ),
            'renew_before_days' => max(0, (int) config('serverpanel.ssl_auto_renew_days', 30)),
            // Reinstall: issue a new certificate even when the current one is valid.
            'force' => $force,
        ]);
        $json = $response->json();
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            $domain->ssl_status = 'failed';
            $domain->ssl_checked_at = now();
            $domain->save();
            throw new \RuntimeException((string) ($json['message'] ?? $response->body() ?: 'SSL ensure failed.'));
        }

        $expiresAt = ! empty($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : null;
        $domain->ssl_status = 'valid';
        $domain->ssl_expires_at = $expiresAt;
        $domain->ssl_checked_at = now();
        $domain->save();

        $certificate = SslCertificate::query()->firstOrNew(['domain' => $domainName]);
        if (! $certificate->exists) {
            $certificate->id = (string) Str::uuid();
        }
        $certificate->fill([
            'certificate_path' => $data['certificate_path'] ?? null,
            'private_key_path' => $data['private_key_path'] ?? null,
            'status' => 'valid',
            'issued_at' => ! empty($data['issued']) ? now() : $certificate->issued_at,
            'renewed_at' => ! empty($data['renewed']) ? now() : $certificate->renewed_at,
            'expires_at' => $expiresAt,
            'auto_renew' => true,
        ])->save();

        // Full reload so the gateway serves the certificate right away. A
        // per-domain reload (fired when enable_ssl is saved, before the cert
        // exists) is not enough on its own.
        app(EdgeGatewayReloader::class)->reload();

        return $data;
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

    protected function drustSslRequest(string $action, array $payload): \Illuminate\Http\Client\Response
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust SSL API is not configured.');
        }

        $request = Http::acceptJson()->asJson()->timeout(300);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request->post(rtrim($baseUrl, '/').'/api/v1/ssl/'.$action, $payload);
    }
}
