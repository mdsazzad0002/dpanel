<?php

namespace App\Http\Controllers\Wordpress;

use App\Http\Controllers\Controller;
use App\Jobs\CloneWebsiteJob;
use App\Jobs\WordpressStagingFinishJob;
use App\Models\Website;
use App\Models\WordpressStaging;
use App\Services\ActivityLogService;
use App\Services\Backup\CloneShareJobStatus;
use App\Services\Wordpress\WordpressInfo;
use App\Services\Wordpress\WordpressSecurityReport;
use App\Services\Wordpress\WordpressSites;
use App\Services\Wordpress\WpCliClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every WordPress site on one page (backlog W1): plugin, theme and core
 * updates (W2), staging copies (W3) and a security check (W4), all through
 * wp-cli run as the site's own user.
 */
class WordpressToolkitController extends Controller
{
    private const SLUG = '/^[a-z0-9][a-z0-9._-]{0,99}$/i';

    public function __construct(
        private readonly WpCliClient $wp,
        private readonly WordpressSites $sites,
        private readonly ActivityLogService $activity,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Wordpress/Index');
    }

    public function sites(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'sites' => $this->sites->list($request->user())]);
    }

    public function info(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);

        return $this->wpCall(fn () => WordpressInfo::fromResults($this->wp->run($website, 'info')));
    }

    public function update(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);
        $validated = $request->validate([
            'kind' => ['required', 'in:core,plugins,themes'],
            'names' => ['nullable', 'array', 'max:200'],
            'names.*' => ['string', 'regex:'.self::SLUG],
        ]);
        $names = $validated['kind'] === 'core' ? [] : array_values(array_unique($validated['names'] ?? []));

        $response = $this->wpCall(function () use ($website, $validated, $names) {
            $this->wp->run($website, 'update_'.$validated['kind'], ['names' => $names]);

            return WordpressInfo::fromResults($this->wp->run($website, 'info'));
        });
        if ($response->getData(true)['ok'] ?? false) {
            $this->activity->log('wordpress.update_'.$validated['kind'], $website, ['names' => $names ?: 'all'], $request);
        }

        return $response;
    }

    public function security(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);

        return $this->wpCall(fn () => ['checks' => WordpressSecurityReport::fromResults($this->wp->run($website, 'security'))]);
    }

    public function fix(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);
        $validated = $request->validate(['fix' => ['required', 'in:'.implode(',', WordpressSecurityReport::FIXES)]]);

        $response = $this->wpCall(function () use ($website, $validated) {
            $this->wp->run($website, 'fix', ['fix' => $validated['fix']]);

            return ['checks' => WordpressSecurityReport::fromResults($this->wp->run($website, 'security'))];
        });
        if ($response->getData(true)['ok'] ?? false) {
            $this->activity->log('wordpress.fix', $website, ['fix' => $validated['fix']], $request);
        }

        return $response;
    }

    // ---- staging (W3) ------------------------------------------------------

    public function staging(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);
        $link = WordpressStaging::query()->where('production_website_id', $website->id)->first();
        $taken = WordpressStaging::query()->pluck('staging_website_id')
            ->merge(WordpressStaging::query()->pluck('production_website_id'))->all();
        $targets = $this->sites->candidates($request->user())
            ->reject(fn (Website $candidate) => $candidate->id === $website->id || in_array($candidate->id, $taken, true))
            ->map(fn (Website $candidate) => ['id' => (string) $candidate->id, 'domain' => (string) $candidate->domain])
            ->values();

        return response()->json(['ok' => true, 'staging' => $link ? $this->describe($link) : null, 'targets' => $targets]);
    }

    public function link(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);
        $validated = $request->validate(['staging_website_id' => ['required', 'uuid']]);
        $target = $this->website($request, $validated['staging_website_id']);
        abort_if($target->id === $website->id, 422, 'A site cannot be its own staging copy.');

        $inUse = WordpressStaging::query()
            ->whereIn('production_website_id', [$website->id, $target->id])
            ->orWhereIn('staging_website_id', [$website->id, $target->id])
            ->exists();
        if ($inUse) {
            return response()->json(['ok' => false, 'message' => 'One of these sites is already part of a staging pair.'], 422);
        }

        $link = WordpressStaging::create([
            'production_website_id' => $website->id,
            'staging_website_id' => $target->id,
            'user_id' => $request->user()->id,
        ]);
        $this->activity->log('wordpress.staging_linked', $website, ['staging' => $target->domain], $request);

        return response()->json(['ok' => true, 'staging' => $this->describe($link)]);
    }

    public function unlink(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);
        $link = WordpressStaging::query()->where('production_website_id', $website->id)->firstOrFail();
        abort_if($link->busy(), 409, 'A copy is still running.');
        $link->delete();

        return response()->json(['ok' => true]);
    }

    /** `to_staging` copies production over staging; `to_production` deploys staging back. */
    public function copy(Request $request, string $token, string $site): JsonResponse
    {
        $website = $this->website($request, $site);
        $validated = $request->validate(['direction' => ['required', 'in:'.WordpressStaging::TO_STAGING.','.WordpressStaging::TO_PRODUCTION]]);
        $link = WordpressStaging::query()->where('production_website_id', $website->id)->firstOrFail();
        if ($link->busy()) {
            return response()->json(['ok' => false, 'message' => 'A copy is already running.'], 409);
        }
        $staging = $this->website($request, (string) $link->staging_website_id);
        [$from, $to] = $validated['direction'] === WordpressStaging::TO_STAGING ? [$website, $staging] : [$staging, $website];

        try {
            $home = trim($this->wp->run($from, 'home_url')['home']['output'] ?? '');
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        if (preg_match('#^https?://#i', $home) !== 1) {
            return response()->json(['ok' => false, 'message' => "The site address of {$from->domain} could not be read."], 422);
        }

        $cloneId = (string) Str::uuid();
        CloneShareJobStatus::set($cloneId, ['stage' => 'queued']);
        $link->update(['clone_id' => $cloneId, 'direction' => $validated['direction'], 'stage' => 'queued', 'message' => null]);

        Bus::chain([
            new CloneWebsiteJob($cloneId, (string) $from->id, (string) $to->id, (int) $request->user()->id),
            new WordpressStagingFinishJob($link->id, $home),
        ])->onQueue('heavy')->dispatch();

        $this->activity->log('wordpress.staging_'.$validated['direction'], $website, ['from' => $from->domain, 'to' => $to->domain], $request);

        return response()->json(['ok' => true, 'staging' => $this->describe($link->fresh())]);
    }

    /** @return array<string, mixed> */
    private function describe(WordpressStaging $link): array
    {
        $stage = $link->stage;
        if ($link->busy() && $link->clone_id !== null) {
            $clone = CloneShareJobStatus::get($link->clone_id);
            if (($clone['stage'] ?? '') === 'failed') {
                $link->update(['stage' => 'failed', 'message' => (string) ($clone['message'] ?? 'The copy failed.')]);
                $stage = 'failed';
            } elseif ($stage === 'queued' && ! in_array($clone['stage'] ?? 'queued', ['queued', 'ready'], true)) {
                $stage = 'copying';
            }
        }

        return [
            'staging_website_id' => (string) $link->staging_website_id,
            'staging_domain' => (string) ($link->staging?->domain ?? ''),
            'direction' => $link->direction,
            'stage' => $stage,
            'message' => $link->message,
            'copied_at' => $link->copied_at?->toIso8601String(),
            'deployed_at' => $link->deployed_at?->toIso8601String(),
        ];
    }

    private function website(Request $request, string $id): Website
    {
        return Website::query()->visibleTo($request->user())->where('type', 'primary')->findOrFail($id);
    }

    private function wpCall(\Closure $work): JsonResponse
    {
        try {
            return response()->json(['ok' => true, ...$work()]);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
