<?php

namespace App\Http\Middleware;

use App\Http\Controllers\PanelSearchController;
use App\Support\UserAccessCache;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $userAccess = $user ? UserAccessCache::get($user) : ['roles' => [], 'permissions' => []];
        $panelToken = $request->hasSession() ? $request->session()->get('panel_session_token') : null;
        $flashSuccess = $request->hasSession() ? fn () => $request->session()->get('success') : fn () => null;
        $flashError = $request->hasSession() ? fn () => $request->session()->get('error') : fn () => null;

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'version' => config('app.version'),
            ],
            'panel' => [
                'token' => $panelToken,
                'domain' => config('serverpanel.panel_domain'),
            ],
            'phpmyadmin' => [
                'url' => trim((string) config('app.phpmyadmin_url', '')),
            ],
            'panelSearch' => fn () => app(PanelSearchController::class)->buildItems($request),
            'flash' => [
                'success' => $flashSuccess,
                'error' => $flashError,
            ],
            'auth' => [
                'user' => $user,
                'roles' => $userAccess['roles'],
                'permissions' => $userAccess['permissions'],
            ],
        ];
    }
}
