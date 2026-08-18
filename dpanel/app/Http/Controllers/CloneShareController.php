<?php

namespace App\Http\Controllers;

use App\Jobs\CloneWebsiteJob;
use App\Jobs\ImportSharedWebsiteJob;
use App\Jobs\ShareWebsitePackageJob;
use App\Models\Website;
use App\Services\Backup\CloneShareJobStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CloneShareController extends Controller
{
    public function page(Request $request, string $token, string $id): Response
    {
        $website = Website::query()->visibleTo($request->user())->findOrFail($id);

        $otherWebsites = Website::query()->visibleTo($request->user())
            ->where('id', '!=', $id)
            ->orderBy('domain')
            ->get(['id', 'domain'])
            ->values();

        return Inertia::render('Websites/CloneShare', [
            'website' => $website,
            'otherWebsites' => $otherWebsites,
        ]);
    }

    public function clone(Request $request, string $token, string $id): JsonResponse
    {
        $data = $request->validate(['target_website_id' => ['required', 'uuid', 'different:id']]);

        $source = Website::query()->visibleTo($request->user())->find($id);
        $target = Website::query()->visibleTo($request->user())->find($data['target_website_id']);
        if (! $source instanceof Website || ! $target instanceof Website) {
            return response()->json(['ok' => false, 'message' => 'Source or target website was not found.'], 404);
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            return response()->json(['ok' => false, 'message' => 'dRust backup API is not configured.'], 503);
        }

        $cloneId = (string) Str::uuid();
        CloneShareJobStatus::set($cloneId, ['stage' => 'queued']);

        CloneWebsiteJob::dispatch($cloneId, (string) $id, (string) $data['target_website_id'], (int) $request->user()->id);

        return response()->json(['ok' => true, 'clone_id' => $cloneId]);
    }

    public function share(Request $request, string $token, string $id): JsonResponse
    {
        $website = Website::query()->visibleTo($request->user())->find($id);
        if (! $website instanceof Website) {
            return response()->json(['ok' => false, 'message' => 'Website account not found.'], 404);
        }

        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        $apiToken = trim((string) config('serverpanel.execution_api_token', ''));
        if ($baseUrl === '' || $apiToken === '') {
            return response()->json(['ok' => false, 'message' => 'dRust backup API is not configured.'], 503);
        }

        $shareId = (string) Str::uuid();
        CloneShareJobStatus::set($shareId, ['stage' => 'queued']);

        ShareWebsitePackageJob::dispatch($shareId, (string) $id, (int) $request->user()->id);

        return response()->json(['ok' => true, 'share_id' => $shareId]);
    }

    public function importFromUrl(Request $request, string $token, string $id): JsonResponse
    {
        $data = $request->validate(['source_url' => ['required', 'url', 'max:2048']]);

        $target = Website::query()->visibleTo($request->user())->find($id);
        if (! $target instanceof Website) {
            return response()->json(['ok' => false, 'message' => 'Website account not found.'], 404);
        }

        $cloneId = (string) Str::uuid();
        CloneShareJobStatus::set($cloneId, ['stage' => 'queued']);

        ImportSharedWebsiteJob::dispatch($cloneId, (string) $data['source_url'], (string) $id, (int) $request->user()->id);

        return response()->json(['ok' => true, 'clone_id' => $cloneId]);
    }

    public function status(Request $request, string $token, string $id, string $jobId): JsonResponse
    {
        Website::query()->visibleTo($request->user())->findOrFail($id);

        $status = CloneShareJobStatus::get($jobId);
        if ($status === null) {
            return response()->json(['ok' => false, 'message' => 'Job not found.'], 404);
        }

        return response()->json(['ok' => true, ...$status]);
    }

    public function download(Request $request, string $downloadToken): BinaryFileResponse|JsonResponse
    {
        $entry = Cache::get('clone-share-download:'.$downloadToken);

        if (! is_array($entry) || ! is_file($entry['path'])) {
            return response()->json(['ok' => false, 'message' => 'This clone link has expired.'], 404);
        }

        return response()->download($entry['path'], $entry['file_name']);
    }
}
