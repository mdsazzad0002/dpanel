<?php

namespace App\Services\Ssl;

use App\Models\Domain;
use App\Models\SslCertificate;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\Website\WebsiteService;

class SslLifecycleService
{
    public function __construct(protected WebsiteService $websiteService)
    {
    }

    /** @return array<string, mixed> */
    public function ensureForWebsite(Website $website): array
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

        $apiUrl = preg_replace(
            '#/api/v1/script/run/?$#',
            '/api/v1/ssl/ensure',
            trim((string) config('serverpanel.execution_api_url', '')),
        ) ?: '';
        if ($apiUrl === '') {
            throw new \RuntimeException('drust SSL API is not configured.');
        }

        $request = Http::acceptJson()->asJson()->timeout(300);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($apiUrl, [
            'domain' => $domainName,
            'root_path' => $this->websiteService->resolveWebsiteDocumentRoot(
                (string) $website->root_path,
                (string) $website->start_directory,
            ),
            'include_www' => (string) $website->type === 'main'
                && ! str_starts_with($domainName, 'www.'),
            'renew_before_days' => max(0, (int) config('serverpanel.ssl_auto_renew_days', 30)),
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

        return $data;
    }
}
