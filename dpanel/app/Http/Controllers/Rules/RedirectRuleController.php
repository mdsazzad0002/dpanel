<?php

namespace App\Http\Controllers\Rules;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteRedirectRule;
use App\Services\EdgeGatewayReloader;
use App\Services\Website\RedirectRuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Rules → Redirect Rules: per-website redirects the edge gateway answers itself. */
class RedirectRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $websites = $this->websites($request)
            ->orderBy('domain')
            ->get(['id', 'domain', 'enable_ssl']);
        $rules = WebsiteRedirectRule::query()
            ->whereIn('website_id', $websites->pluck('id'))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('website_id');

        return Inertia::render('Rules/RedirectRules', [
            'websites' => $websites->map(fn (Website $website): array => [
                'id' => (string) $website->id,
                'domain' => (string) $website->domain,
                'enable_ssl' => (bool) $website->enable_ssl,
                'rules' => ($rules->get($website->id) ?? collect())->map->toRule()->values(),
            ])->values(),
            'statusCodes' => WebsiteRedirectRule::STATUS_CODES,
        ]);
    }

    public function store(Request $request, string $token, EdgeGatewayReloader $reloader, RedirectRuleService $rules): RedirectResponse
    {
        $validated = $request->validate([
            'website_id' => ['required', 'string'],
            'kind' => ['required', Rule::in(WebsiteRedirectRule::KINDS)],
            ...$this->settingsRules(),
        ]);
        $website = $this->website($request, $validated['website_id']);
        $rules->create(
            $website,
            $validated['kind'],
            (string) $validated['name'],
            $validated['target'] ?? null,
            (int) $validated['status_code'],
            (bool) $validated['preserve_query'],
        );

        return $this->applied($reloader, $website, 'Redirect rule created');
    }

    public function update(Request $request, string $token, string $rule, EdgeGatewayReloader $reloader, RedirectRuleService $rules): RedirectResponse
    {
        $rule = WebsiteRedirectRule::query()->findOrFail($rule);
        $website = $this->website($request, $rule->website_id);
        $validated = $request->validate($this->settingsRules());
        $rules->fill($rule, $website, (string) $validated['name'], $validated['target'] ?? null, (int) $validated['status_code'], (bool) $validated['preserve_query']);
        $rule->save();

        return $this->applied($reloader, $website, 'Redirect rule saved');
    }

    public function toggle(Request $request, string $token, string $rule, EdgeGatewayReloader $reloader, RedirectRuleService $rules): RedirectResponse
    {
        $rule = WebsiteRedirectRule::query()->findOrFail($rule);
        $website = $this->website($request, $rule->website_id);
        $rule->enabled = ! $rule->enabled;
        if ($rule->enabled) {
            $rules->assertApplicable($rule->kind, $website);
        }
        $rule->save();

        return $this->applied($reloader, $website, $rule->enabled ? 'Redirect rule enabled' : 'Redirect rule disabled');
    }

    public function destroy(Request $request, string $token, string $rule, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $rule = WebsiteRedirectRule::query()->findOrFail($rule);
        $website = $this->website($request, $rule->website_id);
        $rule->delete();

        return $this->applied($reloader, $website, 'Redirect rule deleted');
    }

    /** @return array<string, mixed> */
    private function settingsRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'target' => ['nullable', 'string', 'max:253'],
            'status_code' => ['required', 'integer', Rule::in(WebsiteRedirectRule::STATUS_CODES)],
            'preserve_query' => ['required', 'boolean'],
        ];
    }

    private function applied(EdgeGatewayReloader $reloader, Website $website, string $message): RedirectResponse
    {
        $live = $reloader->reloadDomains([(string) $website->domain]);

        return back()->with(
            $live ? 'success' : 'error',
            $live ? "{$message} and applied." : "{$message}, but the edge gateway did not confirm the reload; it applies on its next reload.",
        );
    }

    private function website(Request $request, string $id): Website
    {
        return $this->websites($request)->findOrFail($id);
    }

    /** The panel's own site is never redirected from here. */
    private function websites(Request $request): Builder
    {
        return Website::query()
            ->visibleTo($request->user())
            ->where(fn (Builder $query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'));
    }
}
