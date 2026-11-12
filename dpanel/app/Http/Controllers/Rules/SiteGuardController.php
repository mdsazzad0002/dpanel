<?php

namespace App\Http\Controllers\Rules;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Models\WebsiteGuardSetting;
use App\Models\WebsiteProtectedDirectory;
use App\Services\EdgeGatewayReloader;
use App\Support\Platform\PlatformCapabilities;
use App\Support\BasicAuthHash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rules → Site Protection (tasks S5, S6, S2): password-protected folders,
 * folders where PHP never runs, hotlink protection, Cloudflare-only, and the
 * web application firewall. The edge gateway enforces
 * all three before redirects and the cache; an alias uses its main site's.
 */
class SiteGuardController extends Controller
{
    /** A path prefix: `/`, or `/` and segments of letters, digits, `.`, `_`, `-`. */
    private const PATH = '/^\/(?:[A-Za-z0-9._~-]+(?:\/[A-Za-z0-9._~-]+)*)?\/?$/';

    private const HOST = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';

    public function index(Request $request, PlatformCapabilities $capabilities): Response
    {
        $websites = $this->websites($request)->orderBy('domain')->get(['id', 'domain']);
        $ids = $websites->pluck('id');
        $directories = WebsiteProtectedDirectory::query()->whereIn('website_id', $ids)
            ->orderBy('path')->orderBy('username')->get()->groupBy('website_id');
        $settings = WebsiteGuardSetting::query()->whereIn('website_id', $ids)->get()->keyBy('website_id');

        return Inertia::render('Rules/SiteProtection', [
            'websites' => $websites->map(function (Website $website) use ($directories, $settings): array {
                $setting = $settings->get($website->id);

                return [
                    'id' => (string) $website->id,
                    'domain' => (string) $website->domain,
                    'directories' => ($directories->get($website->id) ?? collect())
                        ->groupBy('path')
                        ->map(fn ($users, $path) => [
                            'path' => (string) $path,
                            'realm' => (string) ($users->first()->realm ?? ''),
                            'users' => $users->map(fn (WebsiteProtectedDirectory $user) => ['id' => $user->id, 'username' => $user->username])->values(),
                        ])->values(),
                    'no_php_paths' => $setting?->noPhpPaths() ?? [],
                    'denied_paths' => $setting?->deniedPaths() ?? [],
                    'hotlink_protection' => (bool) ($setting?->hotlink_protection ?? false),
                    'hotlink_allowed_hosts' => $setting?->hotlinkAllowedHosts() ?? [],
                    'cloudflare_only' => (bool) ($setting?->cloudflare_only ?? false),
                    'waf_mode' => (string) ($setting?->waf_mode ?? 'detect'),
                ];
            })->values(),
            // Strictly true: an older drust does not report it at all.
            'wafAvailable' => ($capabilities->report()['capabilities']['waf'] ?? false) === true,
        ]);
    }

    public function storeUser(Request $request, string $token, string $website, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $website = $this->website($request, $website);
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:255', 'regex:'.self::PATH],
            'realm' => ['nullable', 'string', 'max:80', 'not_regex:/["\\\\\r\n]/'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);
        $path = $this->normalizePath($validated['path']);
        $realm = trim((string) ($validated['realm'] ?? '')) ?: null;

        WebsiteProtectedDirectory::query()->updateOrCreate(
            ['website_id' => $website->id, 'path' => $path, 'username' => $validated['username']],
            ['realm' => $realm, 'password_hash' => BasicAuthHash::make($validated['password'])],
        );
        // A folder has one realm, so a new one applies to all its users.
        if ($realm !== null) {
            WebsiteProtectedDirectory::query()->where('website_id', $website->id)->where('path', $path)->update(['realm' => $realm]);
        }

        return $this->applied($reloader, $website, "{$path} now asks {$validated['username']} for a password");
    }

    public function destroyUser(Request $request, string $token, int $user, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $user = WebsiteProtectedDirectory::query()->findOrFail($user);
        $website = $this->website($request, $user->website_id);
        $user->delete();

        return $this->applied($reloader, $website, "{$user->username} removed from {$user->path}");
    }

    public function updateSettings(Request $request, string $token, string $website, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $website = $this->website($request, $website);
        $validated = $request->validate([
            'no_php_paths' => ['present', 'array', 'max:20'],
            'no_php_paths.*' => ['string', 'max:255', 'regex:'.self::PATH, Rule::notIn(['/'])],
            // Set by the app installer; optional so an older page keeps them.
            'denied_paths' => ['sometimes', 'array', 'max:40'],
            'denied_paths.*' => ['string', 'max:255', 'regex:'.self::PATH, Rule::notIn(['/'])],
            'hotlink_protection' => ['required', 'boolean'],
            'hotlink_allowed_hosts' => ['present', 'array', 'max:20'],
            'hotlink_allowed_hosts.*' => ['string', 'max:253', 'regex:'.self::HOST],
            'cloudflare_only' => ['sometimes', 'boolean'],
        ]);
        $paths = collect($validated['no_php_paths'])->map(fn (string $path) => $this->normalizePath($path))->unique()->values();
        $hosts = collect($validated['hotlink_allowed_hosts'])->map(fn (string $host) => strtolower(trim($host)))->unique()->values();

        $denied = array_key_exists('denied_paths', $validated)
            ? ['denied_paths' => collect($validated['denied_paths'])->map(fn (string $path) => $this->normalizePath($path))->unique()->implode(',') ?: null]
            : [];

        WebsiteGuardSetting::query()->updateOrCreate(['website_id' => $website->id], [
            ...$denied,
            'no_php_paths' => $paths->implode(',') ?: null,
            'hotlink_protection' => (bool) $validated['hotlink_protection'],
            'hotlink_allowed_hosts' => $hosts->implode(',') ?: null,
            'cloudflare_only' => (bool) ($validated['cloudflare_only'] ?? false),
        ]);

        return $this->applied($reloader, $website, 'Protection settings saved');
    }

    /** The web application firewall (S2): off, detect only, or block. */
    public function updateWaf(Request $request, string $token, string $website, EdgeGatewayReloader $reloader): RedirectResponse
    {
        $website = $this->website($request, $website);
        $validated = $request->validate([
            'waf_mode' => ['required', Rule::in(WebsiteGuardSetting::WAF_MODES)],
        ]);
        WebsiteGuardSetting::query()->updateOrCreate(['website_id' => $website->id], ['waf_mode' => $validated['waf_mode']]);

        return $this->applied($reloader, $website, match ($validated['waf_mode']) {
            'block' => 'The firewall now blocks attacks',
            'detect' => 'The firewall now logs attacks without blocking',
            default => 'The firewall is off',
        });
    }

    /** `/Admin/` and `/admin` are the same folder to the gateway. */
    private function normalizePath(string $path): string
    {
        $path = '/'.trim(strtolower($path), '/');

        return $path;
    }

    /** Aliases get their main site's rules, so they reload with it. */
    private function applied(EdgeGatewayReloader $reloader, Website $website, string $message): RedirectResponse
    {
        $domains = Website::query()->where('parent_id', $website->id)->pluck('domain')->push($website->domain)->all();
        $live = $reloader->reloadDomains($domains);

        return back()->with(
            $live ? 'success' : 'error',
            $live ? "{$message} and applied." : "{$message}, but the edge gateway did not confirm the reload; it applies on its next reload.",
        );
    }

    private function website(Request $request, string $id): Website
    {
        return $this->websites($request)->findOrFail($id);
    }

    /** Main sites only: an alias follows its main site. The panel's own site is never touched. */
    private function websites(Request $request): Builder
    {
        return Website::query()
            ->visibleTo($request->user())
            ->whereNotIn('type', ['alis', 'alias'])
            ->where(fn (Builder $query) => $query->whereNull('scope')->orWhere('scope', '!=', 'system'));
    }
}
