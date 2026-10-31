<?php

namespace App\Http\Controllers;

use App\Jobs\PushWebsiteToLiveJob;
use App\Models\Website;
use App\Models\WebsitePushToken;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\SitePush\SitePushClient;
use App\Services\Website\WebsiteAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The website's "Push to Live" page. One panel plays either role:
 *  - live server: issue/revoke push tokens for this website (the other panel
 *    calls SitePushApiController with them);
 *  - local server: enter a live panel's host URL + push token and push this
 *    website there (PushWebsiteToLiveJob) — the whole site, or in "files"
 *    mode only the files that differ (.gitignore applies, .git never goes).
 */
class SitePushController extends Controller
{
    private const EXPIRY_HOURS = [1, 24, 168, 720];

    public function page(Request $request, WebsiteAccessService $access, string $token, string $id): Response
    {
        $website = $access->findAuthorizedWebsiteOrFail($id);
        abort_if($this->isSystemWebsite((string) $website['id'], (string) ($website['site_owner'] ?? '')), 404);

        return Inertia::render('Websites/PushToLive', [
            'website' => $website,
            'tokens' => $this->tokens((string) $website['id']),
            'expiryOptions' => self::EXPIRY_HOURS,
            // What the pushing panel enters as "Live panel host URL".
            'panelHostUrl' => rtrim($request->getSchemeAndHttpHost(), '/'),
        ]);
    }

    public function createToken(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'expires_in_hours' => ['required', 'integer', 'in:'.implode(',', self::EXPIRY_HOURS)],
        ]);

        [$pushToken, $plain] = WebsitePushToken::issue(
            $website,
            $request->user(),
            trim((string) ($data['name'] ?? '')) ?: 'Push token',
            now()->addHours((int) $data['expires_in_hours']),
        );

        return response()->json([
            'ok' => true,
            'push_token' => $plain,
            'token' => $this->present($pushToken),
            'tokens' => $this->tokens((string) $website->id),
        ]);
    }

    /** A token's plaintext is never stored, so a lost one can only be replaced. */
    public function regenerateToken(Request $request, string $token, string $id, int $tokenId): JsonResponse
    {
        $website = $this->website($request, $id);
        $pushToken = WebsitePushToken::query()->where('website_id', $website->id)->whereNull('revoked_at')->findOrFail($tokenId);
        $plain = $pushToken->regenerate();

        return response()->json([
            'ok' => true,
            'push_token' => $plain,
            'token' => $this->present($pushToken),
            'tokens' => $this->tokens((string) $website->id),
        ]);
    }

    public function revokeToken(Request $request, string $token, string $id, int $tokenId): JsonResponse
    {
        $website = $this->website($request, $id);
        WebsitePushToken::query()->where('website_id', $website->id)->whereKey($tokenId)->update(['revoked_at' => now()]);

        return response()->json(['ok' => true, 'tokens' => $this->tokens((string) $website->id)]);
    }

    /**
     * Step 1 of a push: checks the host URL + token against the live panel
     * and returns what the token would overwrite there, so the admin can
     * confirm the target domain before anything is packaged or sent.
     */
    public function connect(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        [$hostUrl, $pushToken, $error] = $this->target($request, $website);
        if ($error !== null) {
            return $error;
        }

        try {
            $live = (new SitePushClient($hostUrl, $pushToken))->handshake();
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'host_url' => $hostUrl,
            'live_domain' => (string) ($live['domain'] ?? ''),
            'server' => (string) ($live['server'] ?? ''),
            'token_name' => (string) ($live['token_name'] ?? ''),
            'source_domain' => (string) $website->domain,
        ]);
    }

    /** Step 2: starts the push, only for the target domain the admin confirmed. */
    public function push(Request $request, string $token, string $id): JsonResponse
    {
        $website = $this->website($request, $id);
        [$hostUrl, $pushToken, $error] = $this->target($request, $website);
        if ($error !== null) {
            return $error;
        }
        $options = $request->validate([
            'confirm_domain' => ['required', 'string', 'max:255'],
            'mode' => ['nullable', 'string', 'in:site,files'],
            'delete_missing' => ['nullable', 'boolean'],
        ]);
        $confirmed = strtolower(trim((string) $options['confirm_domain']));
        $mode = (string) ($options['mode'] ?? 'site');

        $pushId = (string) Str::uuid();
        CloneShareJobStatus::set($pushId, ['stage' => 'queued', 'percent' => 0, 'message' => 'Waiting for the backup worker', 'live_url' => $hostUrl]);
        PushWebsiteToLiveJob::dispatch($pushId, (string) $website->id, (int) $request->user()->id, $hostUrl, Crypt::encryptString($pushToken), $confirmed, $mode, $mode === 'files' && (bool) ($options['delete_missing'] ?? false));

        return response()->json(['ok' => true, 'push_id' => $pushId, 'live_domain' => $confirmed, 'mode' => $mode]);
    }

    /** @return array{0:string,1:string,2:JsonResponse|null} normalized host URL, token, or an error response */
    private function target(Request $request, Website $website): array
    {
        $data = $request->validate([
            'host_url' => ['required', 'string', 'max:2048'],
            'push_token' => ['required', 'string', 'starts_with:'.WebsitePushToken::PREFIX, 'max:255'],
        ]);
        $hostUrl = $this->normalizeHostUrl($data['host_url']);
        if ($hostUrl === null) {
            return ['', '', response()->json(['ok' => false, 'message' => 'Enter the live panel host URL, e.g. https://panel.example.com:2083'], 422)];
        }
        $pushToken = trim($data['push_token']);

        // The live panel may well be this same server (pushing one site onto
        // another here); only pushing a website onto itself makes no sense.
        if (WebsitePushToken::findActive($pushToken)?->website_id === (string) $website->id) {
            return ['', '', response()->json(['ok' => false, 'message' => 'This push token is for this same website — use it on the panel you are pushing from.'], 422)];
        }

        return [$hostUrl, $pushToken, null];
    }

    /**
     * Reduces whatever was pasted (a bare host, or a full panel URL with a
     * cpsess path) to scheme://host[:port]; https is assumed when omitted.
     */
    private function normalizeHostUrl(string $value): ?string
    {
        $value = trim($value);
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$value;
        }
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! preg_match('/^[a-z0-9.-]+$|^\[[0-9a-f:]+\]$/', $host)) {
            return null;
        }

        return strtolower((string) $parts['scheme']).'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function status(Request $request, string $token, string $id, string $pushId): JsonResponse
    {
        $this->website($request, $id);
        $status = Str::isUuid($pushId) ? CloneShareJobStatus::get($pushId) : null;
        if ($status === null) {
            return response()->json(['ok' => false, 'message' => 'Push job not found.'], 404);
        }

        return response()->json(['ok' => true, ...$status]);
    }

    private function website(Request $request, string $id): Website
    {
        $website = Website::query()->visibleTo($request->user())->find($id);
        abort_unless($website instanceof Website, 404, 'Website account not found.');
        abort_if($this->isSystemWebsite((string) $website->id, (string) $website->site_owner), 422, "The panel's own website can't be pushed or overwritten.");

        return $website;
    }

    /** dPanel's own installation (see ServicesGrid.vue) — pushing it would overwrite the panel. */
    private function isSystemWebsite(string $id, string $owner): bool
    {
        return $id === '1' || $owner === 'system';
    }

    private function tokens(string $websiteId): array
    {
        return WebsitePushToken::query()
            ->where('website_id', $websiteId)
            ->whereNull('revoked_at')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (WebsitePushToken $token) => $this->present($token))
            ->all();
    }

    private function present(WebsitePushToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'prefix' => $token->token_prefix,
            'active' => $token->isActive(),
            'created_at' => $token->created_at?->toDateTimeString(),
            'expires_at' => $token->expires_at?->toDateTimeString(),
            'last_used_at' => $token->last_used_at?->toDateTimeString(),
            'last_used_ip' => $token->last_used_ip,
            'last_push_status' => $token->last_push_status,
            'last_push_message' => $token->last_push_message,
        ];
    }
}
