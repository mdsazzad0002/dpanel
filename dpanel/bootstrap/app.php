<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;


return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // CORS: only applies to paths listed in config/cors.php (the public
        // website chat widget) — every other route is unaffected.
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Without this the panel builds http:// asset/redirect URLs on an https page.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            \App\Http\Middleware\ApplyPanelRouteDefaults::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\AddPanelNoCacheHeaders::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
            'api/v1/alias',
            'api/v1/chat/completions',
            'api/v1/models',
            'api/whmcs/*',
            'webhooks/chat/telegram/*',
            'webhooks/chat/facebook',
            'webhooks/chat/facebook/*',
            'webhooks/chat/whatsapp/*',
            'webhooks/chat/instagram/*',
            'webhooks/chat/slack/*',
            'widget/chat/*',
        ]);

        $middleware->alias([
            'panel.session' => \App\Http\Middleware\EnsurePanelSessionIsValid::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'role_or_permission' => \App\Http\Middleware\CheckRoleOrPermission::class,
            'ai_gateway.key' => \App\Http\Middleware\AuthenticateAiGatewayApiKey::class,
            'whmcs.auth' => \App\Http\Middleware\AuthenticateWhmcsRequest::class,
            'chatengine.telegram.webhook' => \App\Http\Middleware\VerifyTelegramChatWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->stopIgnoring(TokenMismatchException::class);
        $exceptions->reportable(function (TokenMismatchException $e) {
            $request = request();
            Log::info('DEBUG_CSRF_MISMATCH', [
                'scheme' => $request->getScheme(),
                'secure' => $request->isSecure(),
                'host' => $request->getHost(),
                'path' => $request->path(),
                'method' => $request->method(),
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                'session_token' => $request->hasSession() ? $request->session()->token() : null,
                'header_x_xsrf_token' => $request->header('X-XSRF-TOKEN'),
                'cookie_xsrf_token' => $request->cookie('XSRF-TOKEN'),
                'input_token' => $request->input('_token'),
                'all_cookie_names' => array_keys($request->cookies->all()),
                'session_cookie_name' => config('session.cookie'),
                'session_cookie_value_present' => $request->cookies->has(config('session.cookie')),
            ]);
        });
    })->create();
