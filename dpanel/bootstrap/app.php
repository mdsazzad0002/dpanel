<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;


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
        //
    })->create();
