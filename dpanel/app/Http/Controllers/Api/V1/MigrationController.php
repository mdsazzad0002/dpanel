<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\BuildPanelPackageJob;
use App\Jobs\ImportPanelPackageJob;
use App\Models\DatabaseRequest;
use App\Models\Mailbox;
use App\Models\User;
use App\Models\Website;
use App\Services\PanelLink\PanelPackageStore;
use App\Services\SitePush\FileSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * dPanel ⇄ dPanel live migration, called by another panel's PanelLinkClient
 * with a token made here. `migration:read` lets it take from this panel
 * (inventory, file lists, exports downloaded in chunks); `migration:write`
 * lets it also send here (uploads in chunks, then an import).
 *
 * Everything is driven from the other panel, so a panel with no public
 * address (a local box) can both pull from and push to a live one.
 */
class MigrationController extends ApiController
{
    /** Runtimes a copy can recreate; a Node/Python/Docker site needs its app set up by hand. */
    private const SUPPORTED_RUNTIMES = ['php', 'static', ''];

    public function handshake(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');

        return response()->json(['data' => [
            'server' => (string) gethostname(),
            'version' => (string) config('app.version'),
            'panel_name' => (string) config('app.name'),
            'scopes' => array_values(array_intersect(['migration:read', 'migration:write'], (array) $token?->scopes)),
            'chunk_size' => PanelPackageStore::CHUNK_SIZE,
            'max_chunks' => PanelPackageStore::MAX_CHUNKS,
            'kinds' => ['website', 'files', 'panel'],
        ]]);
    }

    /** What the other panel may pick: every website with its owner, database and mailbox count. */
    public function inventory(Request $request): JsonResponse
    {
        $websites = Website::query()->visibleTo($request->user())->orderBy('domain')->get();
        $owners = User::query()->whereIn('id', $websites->pluck('assigned_user_id')->filter()->unique())->get()->keyBy('id');
        $databases = DatabaseRequest::query()->whereIn('domain', $websites->pluck('domain'))->where('status', 'active')->get()->groupBy('domain');
        $mailboxes = $this->mailboxCounts();

        return response()->json(['data' => [
            'websites' => $websites->map(function (Website $website) use ($owners, $databases, $mailboxes, $websites): array {
                $present = WebsiteController::present($website);
                $owner = $owners->get($website->assigned_user_id);
                $reason = $this->unsupportedReason($website);

                return [
                    'id' => $present['id'],
                    'domain' => $present['domain'],
                    'type' => $present['type'],
                    'parent_domain' => $website->parent_id ? (string) optional($websites->firstWhere('id', $website->parent_id))->domain : null,
                    'php_version' => $present['php_version'],
                    'runtime' => $present['runtime'],
                    'owner' => $owner ? ['name' => $owner->name, 'email' => $owner->email] : null,
                    'databases' => $databases->get($website->domain, collect())->pluck('database_name')->values(),
                    'mailboxes' => (int) ($mailboxes[strtolower((string) $website->domain)] ?? 0),
                    'supported' => $reason === null,
                    'unsupported_reason' => $reason,
                ];
            })->values(),
        ]]);
    }

    /** A website's file list (path, size, sha256; .gitignore applies), to work out what differs. */
    public function manifest(Request $request, FileSyncService $files): JsonResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:255']]);
        $website = $this->websiteByDomain($request, $data['domain']);
        if ($website === null) {
            return $this->notFound('Website');
        }
        try {
            return response()->json(['data' => $files->manifest($website)]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function storeExport(Request $request, PanelPackageStore $store): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:website,files,panel'],
            'domain' => ['required_unless:kind,panel', 'nullable', 'string', 'max:255'],
            'paths' => ['required_if:kind,files', 'array', 'max:200000'],
            'paths.*' => ['string', 'max:4096'],
        ]);
        $website = null;
        if ($data['kind'] !== 'panel') {
            $website = $this->websiteByDomain($request, (string) $data['domain']);
            if ($website === null) {
                return $this->notFound('Website');
            }
            if ($data['kind'] === 'website' && ($reason = $this->unsupportedReason($website)) !== null) {
                return response()->json(['message' => $reason], 422);
            }
        }

        $meta = $store->create('export', [
            'kind' => $data['kind'],
            'status' => 'queued',
            'website_id' => $website?->id,
            'domain' => $website?->domain,
            'paths' => $data['paths'] ?? [],
            'created_by' => $request->user()->id,
            'token_id' => $request->attributes->get('api_token')?->id,
        ]);
        BuildPanelPackageJob::dispatch($meta['id']);

        return response()->json(['data' => $this->presentExport($meta)], 202);
    }

    public function showExport(Request $request, PanelPackageStore $store, string $export): JsonResponse
    {
        $meta = $this->ownPackage($request, $store, $export, 'export');

        return $meta ? response()->json(['data' => $this->presentExport($meta)]) : $this->notFound('Export');
    }

    public function exportChunk(Request $request, PanelPackageStore $store, string $export, int $index): Response|JsonResponse
    {
        $meta = $this->ownPackage($request, $store, $export, 'export');
        if ($meta === null || ($meta['status'] ?? '') !== 'ready') {
            return $this->notFound('Export');
        }
        if ($index < 0 || $index >= (int) $meta['total']) {
            return response()->json(['message' => 'Chunk index is out of range.'], 422);
        }
        $bytes = $store->readChunk($meta, $index);
        $store->update($export, []);

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'X-Chunk-Sha256' => hash('sha256', $bytes),
        ]);
    }

    public function destroyExport(Request $request, PanelPackageStore $store, string $export): JsonResponse
    {
        if ($this->ownPackage($request, $store, $export, 'export') === null) {
            return $this->notFound('Export');
        }
        $store->delete($export);

        return response()->json(['message' => 'Export removed.']);
    }

    public function storeUpload(Request $request, PanelPackageStore $store): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:website,files,panel'],
            'size' => ['required', 'integer', 'min:1'],
            'total' => ['required', 'integer', 'between:1,'.PanelPackageStore::MAX_CHUNKS],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'site' => ['nullable', 'array'],
            'site.domain' => ['required_unless:kind,panel', 'nullable', 'string', 'max:255'],
            'site.type' => ['nullable', 'in:main,sub'],
            'site.parent_domain' => ['nullable', 'string', 'max:255'],
            'site.php_version' => ['nullable', 'string', 'max:10'],
            'site.owner_email' => ['nullable', 'string', 'max:255'],
            'site.archive_name' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
            'options.delete' => ['nullable', 'array', 'max:200000'],
            'options.delete.*' => ['string', 'max:4096'],
            'options.manage_dns' => ['nullable', 'boolean'],
        ]);
        if ($store->chunkCount((int) $data['size']) !== (int) $data['total']) {
            return response()->json(['message' => 'Chunk count does not match the package size.'], 422);
        }
        if ($data['kind'] === 'files' && $this->websiteByDomain($request, (string) $data['site']['domain']) === null) {
            return response()->json(['message' => "{$data['site']['domain']} does not exist on this panel yet — copy the whole website first."], 422);
        }

        $meta = $store->create('upload', [
            'kind' => $data['kind'],
            'status' => 'uploading',
            'size' => (int) $data['size'],
            'total' => (int) $data['total'],
            'sha256' => $data['sha256'],
            'extension' => $data['kind'] === 'website' ? 'zip' : 'tar.gz',
            'site' => $data['site'] ?? [],
            'options' => $data['options'] ?? [],
            'created_by' => $request->user()->id,
            'token_id' => $request->attributes->get('api_token')?->id,
        ]);

        return response()->json(['data' => $this->presentUpload($meta)], 201);
    }

    public function uploadChunk(Request $request, PanelPackageStore $store, string $upload, int $index): JsonResponse
    {
        $meta = $this->ownPackage($request, $store, $upload, 'upload');
        if ($meta === null || ($meta['status'] ?? '') !== 'uploading') {
            return $this->notFound('Upload');
        }
        $data = $request->validate([
            'chunk' => ['required', 'file', 'max:'.(int) (PanelPackageStore::CHUNK_SIZE / 1024 + 64)],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        if ($index < 0 || $index >= (int) $meta['total']) {
            return response()->json(['message' => 'Chunk index is out of range.'], 422);
        }
        $file = $request->file('chunk');
        if (hash_file('sha256', $file->getRealPath()) !== $data['sha256']) {
            return response()->json(['message' => 'Chunk was corrupted in transit — send it again.'], 422);
        }
        // Sending an index again (a retry after a lost response) replaces it.
        $file->move(dirname($store->chunkPath($upload, $index)), basename($store->chunkPath($upload, $index)));
        $store->update($upload, []);

        return response()->json(['data' => ['index' => $index]]);
    }

    public function completeUpload(Request $request, PanelPackageStore $store, string $upload): JsonResponse
    {
        $meta = $this->ownPackage($request, $store, $upload, 'upload');
        if ($meta === null) {
            return $this->notFound('Upload');
        }
        // A retry after the first call's response was lost: the import is already queued.
        if (($meta['status'] ?? '') !== 'uploading') {
            return response()->json(['data' => $this->presentUpload($meta)], 202);
        }
        try {
            $path = $store->assemble($meta);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $meta = $store->update($upload, ['status' => 'queued', 'path' => $path]);
        ImportPanelPackageJob::dispatch($upload);

        return response()->json(['data' => $this->presentUpload($meta)], 202);
    }

    public function showUpload(Request $request, PanelPackageStore $store, string $upload): JsonResponse
    {
        $meta = $this->ownPackage($request, $store, $upload, 'upload');

        return $meta ? response()->json(['data' => $this->presentUpload($meta)]) : $this->notFound('Upload');
    }

    private function websiteByDomain(Request $request, string $domain): ?Website
    {
        return Website::query()->visibleTo($request->user())->where('domain', strtolower(trim($domain)))->first();
    }

    /** A package is only visible to the token owner that made it. */
    private function ownPackage(Request $request, PanelPackageStore $store, string $id, string $type): ?array
    {
        $meta = $store->find($id, $type);

        return $meta !== null && (int) ($meta['created_by'] ?? 0) === (int) $request->user()->id ? $meta : null;
    }

    private function unsupportedReason(Website $website): ?string
    {
        if ((string) $website->id === '1' || $website->site_owner === 'system') {
            return "The panel's own website is not copied.";
        }
        if (in_array((string) $website->type, ['alis', 'alias'], true)) {
            return 'An alias is recreated on its main website, not copied.';
        }
        if (! in_array((string) ($website->runtime ?? ''), self::SUPPORTED_RUNTIMES, true)) {
            return ucfirst((string) $website->runtime).' apps are not copied yet: copy the files with "Sync files" after creating the app on the other panel.';
        }

        return null;
    }

    /** @return array<string, int> mailboxes per domain */
    private function mailboxCounts(): array
    {
        try {
            return Mailbox::query()->selectRaw('LOWER(domain) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd')->map(fn ($count) => (int) $count)->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function presentExport(array $meta): array
    {
        return array_intersect_key($meta, array_flip(['id', 'kind', 'status', 'domain', 'name', 'size', 'sha256', 'chunk_size', 'total', 'has_database', 'warning', 'message']));
    }

    private function presentUpload(array $meta): array
    {
        return array_intersect_key($meta, array_flip(['id', 'kind', 'status', 'stage', 'size', 'total', 'message', 'result'])) + ['chunk_size' => PanelPackageStore::CHUNK_SIZE];
    }
}
