<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\ActivityLogService;
use App\Services\Seo\PublicUrl;
use App\Services\Seo\RobotsTxtFile;
use App\Services\Seo\SitemapVerifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** SEO Tools → Sitemap Verify. */
class SitemapVerifyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Seo/SitemapVerify', [
            'websites' => $this->websites($request)
                ->orderBy('domain')
                ->get(['id', 'domain', 'enable_ssl'])
                ->map(fn (Website $website): array => [
                    'id' => (string) $website->id,
                    'domain' => (string) $website->domain,
                    'enable_ssl' => (bool) $website->enable_ssl,
                ])->values(),
        ]);
    }

    /** Step 1: robots.txt and the sitemaps found; none of them parsed yet. */
    public function verify(Request $request, string $token, SitemapVerifier $verifier, RobotsTxtFile $robots): JsonResponse
    {
        [$host, $https, $path] = $this->target($request);
        $report = $verifier->discover($host, $https, $path);
        // Internal sites can have their robots.txt fixed from the report.
        $report['robots']['file'] = $request->input('source', 'internal') === 'internal'
            ? $robots->locate($this->websites($request)->findOrFail($request->input('website_id')))
            : ['editable' => false, 'reason' => 'External sites are read-only.', 'path' => null, 'exists' => false];

        return response()->json($report);
    }

    /** Writes robots.txt into an internal website's document root. */
    public function saveRobots(Request $request, string $token, RobotsTxtFile $robots, ActivityLogService $activity): JsonResponse
    {
        $validated = $request->validate([
            'website_id' => ['required', 'string'],
            'content' => ['present', 'nullable', 'string', 'max:'.RobotsTxtFile::MAX_BYTES],
        ]);
        $website = $this->websites($request)->findOrFail($validated['website_id']);

        try {
            $robots->save($website, (string) ($validated['content'] ?? ''));
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'robots.txt was not saved: '.$exception->getMessage()], 422);
        }
        $activity->log('seo.robots_saved', $website, ['domain' => $website->domain], $request);

        return response()->json(['message' => 'robots.txt saved. Crawlers see it on their next visit.', 'file' => $robots->locate($website)]);
    }

    /** Step 2, on demand: one sitemap file, opened from the list or from its parent index. */
    public function inspect(Request $request, string $token, SitemapVerifier $verifier): JsonResponse
    {
        $sitemapUrl = (string) $request->validate(['sitemap_url' => ['required', 'string', 'max:2048']])['sitemap_url'];
        [$host, $https] = $this->target($request);

        try {
            return response()->json($verifier->inspect($host, $https, $sitemapUrl));
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['sitemap_url' => $exception->getMessage()]);
        }
    }

    /** Fetches a handful of the pages a sitemap lists. */
    public function pages(Request $request, string $token, SitemapVerifier $verifier): JsonResponse
    {
        $urls = $request->validate([
            'urls' => ['required', 'array', 'max:10'],
            'urls.*' => ['string', 'max:2048'],
        ])['urls'];
        [$host, $https] = $this->target($request);

        return response()->json($verifier->checkPages($host, $https, $urls));
    }

    /**
     * The host to check, whether it is HTTPS, and an optional sitemap path:
     * one of the user's websites (internal, the default) or any public URL.
     *
     * @return array{0: string, 1: bool, 2: string|null}
     */
    private function target(Request $request): array
    {
        $validated = $request->validate([
            'source' => ['nullable', 'in:internal,external'],
            'website_id' => ['required_unless:source,external', 'nullable', 'string'],
            // A path on the site itself, never a full URL: only the site's own hosts are fetched.
            'sitemap_path' => ['nullable', 'string', 'max:255', 'regex:/^\/?[A-Za-z0-9._~\-\/%?=&]*$/'],
            'url' => ['required_if:source,external', 'nullable', 'string', 'max:2048'],
        ], [
            'sitemap_path.regex' => 'Enter a path on this website, such as /sitemap.xml.',
            'website_id.required_unless' => 'Select a website.',
            'url.required_if' => 'Enter a site or sitemap URL.',
        ]);

        if (($validated['source'] ?? 'internal') === 'external') {
            // A site URL (robots.txt discovery) or a sitemap URL (checked directly).
            return PublicUrl::parse((string) $validated['url']);
        }
        $website = $this->websites($request)->findOrFail($validated['website_id']);

        return [(string) $website->domain, (bool) $website->enable_ssl, $validated['sitemap_path'] ?? null];
    }

    /** The panel's own site has no sitemap to verify. */
    private function websites(Request $request): Builder
    {
        return Website::query()
            ->visibleTo($request->user())
            ->where(fn (Builder $query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'));
    }
}
