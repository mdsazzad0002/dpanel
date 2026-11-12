<?php

namespace App\Http\Controllers;

use App\Jobs\RunPanelTransferJob;
use App\Models\PanelConnection;
use App\Models\PanelTransfer;
use App\Models\User;
use App\Models\Website;
use App\Services\PanelLink\PanelLinkClient;
use App\Services\PanelLink\PanelLinkException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Migrate → DPanel to DPanel → "Live connection" (admin only). Saves links to
 * other panels and starts transfers over them (RunPanelTransferJob); the other
 * panel answers through Api\V1\MigrationController with a token made there.
 */
class PanelLinkController extends Controller
{
    public function index(Request $request, string $token): JsonResponse
    {
        return response()->json([
            'connections' => PanelConnection::query()->orderBy('name')->get()->map->present()->values(),
            'transfers' => PanelTransfer::query()->latest()->limit(50)->get()->map->present()->values(),
            'local_websites' => Website::query()->visibleTo($request->user())->orderBy('domain')->get()
                ->reject(fn (Website $website) => (string) $website->id === '1' || $website->site_owner === 'system')
                ->map(fn (Website $website) => [
                    'id' => (string) $website->id,
                    'domain' => (string) $website->domain,
                    'type' => (string) $website->type,
                    'runtime' => (string) ($website->runtime ?: 'php'),
                ])->values(),
            'local_users' => User::query()->orderBy('name')->get(['id', 'name', 'email'])->values(),
            // What the other panel enters as this panel's address.
            'panel_url' => rtrim($request->getSchemeAndHttpHost(), '/'),
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'base_url' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'starts_with:dp-api-', 'max:255'],
        ]);
        try {
            $url = PanelLinkClient::normalizeUrl($data['base_url']);
            if ($url === rtrim($request->getSchemeAndHttpHost(), '/')) {
                return response()->json(['message' => 'That is this panel\'s own address.'], 422);
            }
            $remote = (new PanelLinkClient($url, $data['token']))->handshake();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (($remote['scopes'] ?? []) === []) {
            return response()->json(['message' => 'The token needs the migration:read or migration:write scope.'], 422);
        }

        $connection = PanelConnection::query()->create([
            'user_id' => $request->user()->id,
            'name' => trim((string) ($data['name'] ?? '')) ?: (string) ($remote['server'] ?? parse_url($url, PHP_URL_HOST)),
            'base_url' => $url,
            'token' => $data['token'],
            'remote_server' => $remote['server'] ?? null,
            'remote_version' => $remote['version'] ?? null,
            'remote_scopes' => $remote['scopes'],
            'last_checked_at' => now(),
        ]);

        return response()->json(['connection' => $connection->present()], 201);
    }

    public function test(Request $request, string $token, PanelConnection $connection): JsonResponse
    {
        try {
            $remote = PanelLinkClient::for($connection)->handshake();
            $connection->update([
                'remote_server' => $remote['server'] ?? null,
                'remote_version' => $remote['version'] ?? null,
                'remote_scopes' => $remote['scopes'] ?? [],
                'last_checked_at' => now(),
                'last_error' => null,
            ]);
        } catch (PanelLinkException $e) {
            $connection->update(['last_checked_at' => now(), 'last_error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage(), 'connection' => $connection->present()], 422);
        }

        return response()->json(['connection' => $connection->present()]);
    }

    public function destroy(Request $request, string $token, PanelConnection $connection): JsonResponse
    {
        if ($connection->transfers()->whereIn('status', PanelTransfer::ACTIVE)->exists()) {
            return response()->json(['message' => 'A transfer over this connection is still running.'], 422);
        }
        $connection->delete();

        return response()->json(['message' => 'Connection removed. The token still works on the other panel until you delete it there.']);
    }

    public function inventory(Request $request, string $token, PanelConnection $connection): JsonResponse
    {
        try {
            return response()->json(PanelLinkClient::for($connection)->inventory());
        } catch (PanelLinkException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Starts one transfer per website, or one for the whole panel. Websites
     * that are subdomains go after their parents so the parent exists first.
     */
    public function transfer(Request $request, string $token, PanelConnection $connection): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:pull,push'],
            'kind' => ['required', 'in:website,files,panel'],
            'sites' => ['required_unless:kind,panel', 'array', 'max:500'],
            'sites.*.domain' => ['required', 'string', 'max:255'],
            'sites.*.type' => ['nullable', 'in:main,sub,alias'],
            'sites.*.parent_domain' => ['nullable', 'string', 'max:255'],
            'sites.*.php_version' => ['nullable', 'string', 'max:10'],
            'sites.*.owner_email' => ['nullable', 'string', 'max:255'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'manage_dns' => ['boolean'],
            'delete_missing' => ['boolean'],
            'confirmation' => ['required_if:kind,panel', 'nullable', 'in:CLONE'],
        ]);
        if ($data['direction'] === 'pull' && ! $connection->canPull()) {
            return response()->json(['message' => 'This connection\'s token can\'t take from the other panel.'], 422);
        }
        if ($data['direction'] === 'push' && ! $connection->canPush()) {
            return response()->json(['message' => 'This connection\'s token needs migration:write to send to the other panel.'], 422);
        }

        $sites = $data['kind'] === 'panel'
            ? [['domain' => null]]
            : collect($data['sites'])->sortBy(fn (array $site) => ($site['type'] ?? 'main') === 'sub' ? 1 : 0)->values()->all();
        $busy = PanelTransfer::query()->where('connection_id', $connection->id)->whereIn('status', PanelTransfer::ACTIVE)
            ->whereIn('domain', array_filter(array_column($sites, 'domain')))->pluck('domain')->all();

        $transfers = DB::transaction(fn () => collect($sites)
            ->reject(fn (array $site) => $site['domain'] !== null && in_array(strtolower($site['domain']), array_map('strtolower', $busy), true))
            ->map(fn (array $site) => PanelTransfer::query()->create([
                'connection_id' => $connection->id,
                'user_id' => $request->user()->id,
                'direction' => $data['direction'],
                'kind' => $data['kind'],
                'domain' => $site['domain'] !== null ? strtolower($site['domain']) : null,
                'status' => 'queued',
                'message' => 'Waiting for the heavy queue worker',
                'options' => array_filter([
                    'site' => $site['domain'] !== null ? array_intersect_key($site, array_flip(['type', 'parent_domain', 'php_version', 'owner_email'])) : null,
                    'owner_id' => $data['owner_id'] ?? null,
                    'manage_dns' => (bool) ($data['manage_dns'] ?? false),
                    'delete_missing' => (bool) ($data['delete_missing'] ?? false),
                ], fn ($value) => $value !== null),
            ]))
            ->values());

        // A subdomain's job waits while its parent is still being copied (RunPanelTransferJob).
        $transfers->each(fn (PanelTransfer $transfer) => RunPanelTransferJob::dispatch($transfer->id)->afterCommit());

        return response()->json([
            'transfers' => $transfers->map->present()->values(),
            'skipped' => array_values($busy),
        ], 202);
    }

    public function transfers(Request $request, string $token): JsonResponse
    {
        return response()->json(['transfers' => PanelTransfer::query()->latest()->limit(50)->get()->map->present()->values()]);
    }

    public function cancel(Request $request, string $token, PanelTransfer $transfer): JsonResponse
    {
        if (! $transfer->isActive()) {
            return response()->json(['message' => 'This transfer has already finished.'], 422);
        }
        // The running job checks the status before each step and stops.
        $transfer->update(['status' => 'failed', 'message' => 'Cancelled by '.$request->user()->name.'.', 'finished_at' => now()]);

        return response()->json(['transfer' => $transfer->present()]);
    }
}
