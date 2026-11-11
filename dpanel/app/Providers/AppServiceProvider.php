<?php

namespace App\Providers;

use App\Services\ServerPanel\Contracts\AiSuggestionProvider;
use App\Services\ServerPanel\HeuristicAiSuggestionProvider;
use App\Services\ServerPanel\OpenAiSuggestionProvider;
use App\Services\AiGateway\AiGatewayService;
use App\Services\Dns\DnsRegistryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\Platform\PlatformCapabilities::class);
        $this->app->singleton(DnsRegistryService::class);
        // Spec 6.10: PowerDNS is the only backend so far (Windows DNS J6, Cloudflare K5).
        $this->app->bind(\App\Services\Dns\Backend\DnsBackend::class, fn ($app) => match ((string) config('dns.engine', 'powerdns')) {
            default => $app->make(\App\Services\Dns\Backend\PowerDnsBackend::class),
        });
        // Spec 6.11: Postfix and Dovecot until Stalwart (J5, K4).
        $this->app->bind(\App\Services\Mail\Backend\MailBackend::class, fn ($app) => match ((string) config('mail.backend', 'postfix-dovecot')) {
            default => $app->make(\App\Services\Mail\Backend\PostfixDovecotBackend::class),
        });
        // Panel mail goes out from the admin's chosen system mailbox. Applied
        // when the mailer is first needed, so other requests skip the lookup.
        $this->app->afterResolving('mail.manager', fn () => $this->app->make(\App\Services\Mail\SystemMailer::class)->apply());
        // One per request: DNS answers are reused across the mail checks of a page.
        $this->app->scoped(\App\Services\Dns\PublicDnsLookup::class);
        $this->app->singleton(AiGatewayService::class);
        $this->app->bind(AiSuggestionProvider::class, function ($app) {
            $provider = (string) config('serverpanel.ai.provider', 'heuristic');
            $openAiKey = (string) config('services.openai.api_key', '');

            if ($provider === 'openai' && $openAiKey !== '') {
                return $app->make(OpenAiSuggestionProvider::class);
            }

            return $app->make(HeuristicAiSuggestionProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->shouldBypassLoopbackViteHotFile()) {
            // Force manifest-based assets when a stale loopback hot file exists.
            Vite::useHotFile(storage_path('framework/vite.hot'));
        }

        Vite::prefetch(concurrency: 3);
        Schema::defaultStringLength(120);

        // Let $user->can('some_permission_name') resolve against our custom
        // role/permission system, replacing Spatie's equivalent Gate hook.
        Gate::before(function ($user, string $ability) {
            return method_exists($user, 'hasAccess') && $user->hasAccess($ability) ? true : null;
        });

        // Public API (U1): one bucket per token, across every /api/v1 route.
        RateLimiter::for('api-v1', function (Request $request) {
            $token = $request->attributes->get('api_token');

            return Limit::perMinute(max(1, (int) config('api.rate_limit', 120)))
                ->by($token ? 'token:'.$token->id : 'ip:'.$request->ip());
        });
    }

    private function shouldBypassLoopbackViteHotFile(): bool
    {
        if ($this->app->runningInConsole()) {
            return false;
        }

        $hotFile = public_path('hot');
        if (! is_file($hotFile)) {
            return false;
        }

        $hotUrl = trim((string) @file_get_contents($hotFile));
        if ($hotUrl === '') {
            return false;
        }

        $hotHost = strtolower((string) parse_url($hotUrl, PHP_URL_HOST));
        if (! in_array($hotHost, ['127.0.0.1', '::1', 'localhost'], true)) {
            return false;
        }

        $requestHost = strtolower((string) request()->getHost());

        if (str_ends_with($requestHost, '.localhost')) {
            return false;
        }

        return ! in_array($requestHost, ['127.0.0.1', '::1', 'localhost'], true);
    }
}
