<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Seo\PublicUrl;
use App\Services\Seo\UrlInspector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** SEO Tools → URL Inspection. */
class UrlInspectionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Seo/UrlInspection', [
            'websites' => $this->websites($request)
                ->orderBy('domain')
                ->get(['id', 'domain', 'enable_ssl'])
                ->map(fn (Website $website): array => [
                    'id' => (string) $website->id,
                    'domain' => (string) $website->domain,
                    'enable_ssl' => (bool) $website->enable_ssl,
                ])->values(),
            'agents' => collect(UrlInspector::AGENTS)->map(fn (array $agent, string $key): array => ['value' => $key, 'label' => $agent['label']])->values(),
        ]);
    }

    public function inspect(Request $request, string $token, UrlInspector $inspector): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['nullable', 'in:internal,external'],
            'website_id' => ['required_unless:source,external', 'nullable', 'string'],
            // A path on the site itself, never a full URL: only the site's own host is fetched.
            'path' => ['nullable', 'string', 'max:1024', 'regex:/^\/?[A-Za-z0-9._~\-\/%?=&+,;!$\'()*]*$/'],
            'url' => ['required_if:source,external', 'nullable', 'string', 'max:2048'],
            'agent' => ['nullable', Rule::in(array_keys(UrlInspector::AGENTS))],
        ], [
            'path.regex' => 'Enter a path on this website, such as /about.',
            'website_id.required_unless' => 'Select a website.',
            'url.required_if' => 'Enter a page URL.',
        ]);

        if (($validated['source'] ?? 'internal') === 'external') {
            [$host, $https, $path] = PublicUrl::parse((string) $validated['url']);
        } else {
            $website = $this->websites($request)->findOrFail($validated['website_id']);
            [$host, $https, $path] = [(string) $website->domain, (bool) $website->enable_ssl, $validated['path'] ?? null];
        }
        $url = ($https ? 'https' : 'http').'://'.strtolower($host).'/'.ltrim((string) $path, '/');

        return response()->json($inspector->inspect($url, $validated['agent'] ?? 'default'));
    }

    /** The panel's own site is not inspected. */
    private function websites(Request $request): Builder
    {
        return Website::query()
            ->visibleTo($request->user())
            ->where(fn (Builder $query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'));
    }
}
