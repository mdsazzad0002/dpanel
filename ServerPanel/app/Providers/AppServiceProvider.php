<?php

namespace App\Providers;

use App\Services\ServerPanel\Contracts\AiSuggestionProvider;
use App\Services\ServerPanel\HeuristicAiSuggestionProvider;
use App\Services\ServerPanel\OpenAiSuggestionProvider;
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
        if ($requestHost === '') {
            return false;
        }

        return ! in_array($requestHost, ['127.0.0.1', '::1', 'localhost'], true);
    }
}
