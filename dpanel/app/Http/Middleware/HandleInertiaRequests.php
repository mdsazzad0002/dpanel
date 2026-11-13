<?php

namespace App\Http\Middleware;

use App\Http\Controllers\PanelSearchController;
use App\Services\Mcp\GatewayChatTools;
use App\Services\Docker\DrustDockerClient;
use App\Services\PathService;
use App\Support\Platform\PlatformCapabilities;
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
        $facebookPostUrl = $request->hasSession() ? fn () => $request->session()->get('facebook_post_url') : fn () => null;

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'version' => config('app.version'),
            ],
            // The reseller's or the server's name, logo and colour (U4).
            'branding' => fn () => app(\App\Services\Branding\BrandingResolver::class)->forUser($user),
            // Re-sent on every visit: a login/logout regenerates it without a full page load.
            'csrf_token' => $request->hasSession() ? $request->session()->token() : null,
            'panel' => [
                'token' => $panelToken,
                'domain' => config('dpanel.panel_domain'),
                'serverBaseDir' => $user ? PathService::websiteBaseDirectory() : null,
            ],
            'phpmyadmin' => [
                'url' => trim((string) config('app.phpmyadmin_url', '')),
            ],
            // Optional add-ons; their menus stay hidden until they are installed.
            'features' => [
                'docker' => fn () => app(DrustDockerClient::class)->installed(),
            ],
            // What this OS and profile can do; the menu leaves out closedRoutes.
            'platform' => function (): array {
                $capabilities = app(PlatformCapabilities::class);

                return $capabilities->report() + ['closedRoutes' => $capabilities->closedRoutes()];
            },
            'panelSearch' => fn () => app(PanelSearchController::class)->buildItems($request),
            'flash' => [
                'success' => $flashSuccess,
                'error' => $flashError,
                'facebook_post_url' => $facebookPostUrl,
            ],
            'auth' => [
                'user' => $user,
                'roles' => $userAccess['roles'],
                'permissions' => $userAccess['permissions'],
                // The AI chats ask with read-only server tools when this is on.
                'can_use_server_tools' => GatewayChatTools::allowedFor($user),
                'impersonation' => $request->hasSession() && $request->session()->has('impersonation.admin_id')
                    ? [
                        'active' => true,
                        'admin_name' => $request->session()->get('impersonation.admin_name'),
                    ]
                    : null,
            ],
        ];
    }
}
