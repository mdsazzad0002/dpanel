<?php

namespace App\Http\Controllers\Wordpress;

use App\Http\Controllers\Controller;
use App\Jobs\WordpressSafeUpdateJob;
use App\Models\Website;
use App\Models\WordpressStaging;
use App\Services\ActivityLogService;
use App\Services\Wordpress\WordpressStagingCopier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backlog W5: an update that is tried on a fresh staging copy first, and
 * only reaches production when the copy still works.
 */
class WordpressSafeUpdateController extends Controller
{
    private const SLUG = '/^[a-z0-9][a-z0-9._-]{0,99}$/i';

    public function __construct(
        private readonly WordpressStagingCopier $copier,
        private readonly ActivityLogService $activity,
    ) {}

    public function __invoke(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);
        $validated = $request->validate([
            'kind' => ['required', 'in:core,plugins,themes'],
            'names' => ['nullable', 'array', 'max:200'],
            'names.*' => ['string', 'regex:'.self::SLUG],
        ]);
        $names = $validated['kind'] === 'core' ? [] : array_values(array_unique($validated['names'] ?? []));

        $link = WordpressStaging::query()->where('production_website_id', $website->id)->first();
        if ($link === null) {
            return response()->json(['ok' => false, 'message' => 'Link a staging site first: the update is tried there before production.'], 422);
        }
        if ($link->busy()) {
            return response()->json(['ok' => false, 'message' => 'A copy or update is already running.'], 409);
        }
        $staging = $this->website($request, (string) $link->staging_website_id);

        try {
            $this->copier->start($link, $website, $staging, WordpressStaging::SAFE_UPDATE, (int) $request->user()->id,
                fn (string $home) => [new WordpressSafeUpdateJob($link->id, $validated['kind'], $names, $home)]);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $this->activity->log('wordpress.safe_update_'.$validated['kind'], $website, ['names' => $names ?: 'all', 'staging' => $staging->domain], $request);

        return response()->json(['ok' => true, 'staging' => $this->copier->describe($link->fresh())]);
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->where('type', 'primary')->findOrFail($id);
    }
}
