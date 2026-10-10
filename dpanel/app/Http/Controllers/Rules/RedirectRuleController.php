<?php

namespace App\Http\Controllers\Rules;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteRedirectRule;
use App\Services\EdgeGatewayReloader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request, string $token, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $validated = $request->validate([
            'website_id' => ['required', 'string'],
            'kind' => ['required', Rule::in(WebsiteRedirectRule::KINDS)],
            ...$this->settingsRules(),
        ]);
        $website = $this->website($request, $validated['website_id']);
        if (WebsiteRedirectRule::query()->where('website_id', $website->id)->where('kind', $validated['kind'])->exists()) {
            throw ValidationException::withMessages(['kind' => "{$website->domain} already has this redirect rule."]);
        }

        $rule = new WebsiteRedirectRule([
            'website_id' => (string) $website->id,
            'kind' => $validated['kind'],
            'position' => (int) WebsiteRedirectRule::query()->where('website_id', $website->id)->max('position') + 1,
            'enabled' => true,
        ]);
        $this->fill($rule, $website, $validated);
        $rule->save();

        return $this->applied($reloader, $website, 'Redirect rule created');
    }

    public function update(Request $request, string $token, string $rule, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $rule = WebsiteRedirectRule::query()->findOrFail($rule);
        $website = $this->website($request, $rule->website_id);
        $this->fill($rule, $website, $request->validate($this->settingsRules()));
        $rule->save();

        return $this->applied($reloader, $website, 'Redirect rule saved');
    }

    public function toggle(Request $request, string $token, string $rule, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $rule = WebsiteRedirectRule::query()->findOrFail($rule);
        $website = $this->website($request, $rule->website_id);
        $rule->enabled = ! $rule->enabled;
        if ($rule->enabled) {
            $this->assertApplicable($rule->kind, $website);
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

    /** @param array<string, mixed> $validated */
    private function fill(WebsiteRedirectRule $rule, Website $website, array $validated): void
    {
        $this->assertApplicable($rule->kind, $website);
        $target = null;
        if ($rule->kind === 'to_domain') {
            $target = strtolower(rtrim(trim((string) ($validated['target'] ?? '')), '.'));
            $domain = strtolower((string) $website->domain);
            if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $target)) {
                throw ValidationException::withMessages(['target' => 'Enter a hostname such as example.com, without https:// or a path.']);
            }
            if ($target === $domain || $target === "www.{$domain}") {
                throw ValidationException::withMessages(['target' => 'The target must be a different domain than the website itself.']);
            }
        }

        $rule->fill([
            'name' => trim((string) $validated['name']),
            'target' => $target,
            'status_code' => (int) $validated['status_code'],
            'preserve_query' => (bool) $validated['preserve_query'],
        ]);
    }

    private function assertApplicable(string $kind, Website $website): void
    {
        if ($kind === 'http_to_https' && ! $website->enable_ssl) {
            throw ValidationException::withMessages(['website_id' => "{$website->domain} has no SSL certificate yet. Issue one first, or HTTPS visitors would land on an error."]);
        }
        if ($kind === 'www_to_root' && str_starts_with(strtolower((string) $website->domain), 'www.')) {
            throw ValidationException::withMessages(['website_id' => "{$website->domain} is itself a www hostname."]);
        }
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
