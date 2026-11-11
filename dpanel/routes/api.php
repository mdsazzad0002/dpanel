<?php

use App\Http\Controllers\Api\V1\DatabaseController;
use App\Http\Controllers\Api\V1\DnsController;
use App\Http\Controllers\Api\V1\MailboxController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\WebsiteController;
use Illuminate\Support\Facades\Route;

// Public REST API (backlog U1), under /api/v1. `Authorization: Bearer dp-api-...`,
// issued under Profile menu → API Tokens; no session, cookies or CSRF. Every
// route here is described in resources/api/openapi.yaml (ApiOpenApiTest checks).
// The AI Gateway's /api/v1/models, /chat/completions, ... are in web.php.

Route::prefix('v1')->middleware('api.json')->group(function (): void {
    Route::get('/openapi.yaml', [MetaController::class, 'openapi'])->middleware('throttle:60,1')->name('api.v1.openapi');

    Route::middleware(['api.token', 'throttle:api-v1'])->group(function (): void {
        Route::get('/me', [MetaController::class, 'me'])->name('api.v1.me');

        Route::get('/websites', [WebsiteController::class, 'index'])->middleware('api.scope:websites:read')->name('api.v1.websites.index');
        Route::get('/websites/{website}', [WebsiteController::class, 'show'])->middleware('api.scope:websites:read')->name('api.v1.websites.show');
        Route::post('/websites', [WebsiteController::class, 'store'])->middleware('api.scope:websites:write')->name('api.v1.websites.store');
        Route::delete('/websites/{website}', [WebsiteController::class, 'destroy'])->middleware('api.scope:websites:write')->name('api.v1.websites.destroy');

        Route::get('/dns/zones', [DnsController::class, 'zones'])->middleware('api.scope:dns:read')->name('api.v1.dns.zones');
        Route::get('/dns/zones/{zone}/records', [DnsController::class, 'index'])->middleware('api.scope:dns:read')->name('api.v1.dns.records.index');
        Route::post('/dns/zones/{zone}/records', [DnsController::class, 'store'])->middleware('api.scope:dns:write')->name('api.v1.dns.records.store');
        Route::put('/dns/zones/{zone}/records/{record}', [DnsController::class, 'update'])->middleware('api.scope:dns:write')->name('api.v1.dns.records.update');
        Route::delete('/dns/zones/{zone}/records/{record}', [DnsController::class, 'destroy'])->middleware('api.scope:dns:write')->name('api.v1.dns.records.destroy');

        Route::get('/databases', [DatabaseController::class, 'index'])->middleware('api.scope:databases:read')->name('api.v1.databases.index');
        Route::post('/databases', [DatabaseController::class, 'store'])->middleware('api.scope:databases:write')->name('api.v1.databases.store');
        Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->middleware('api.scope:databases:write')->name('api.v1.databases.destroy');

        Route::get('/mailboxes', [MailboxController::class, 'index'])->middleware('api.scope:mail:read')->name('api.v1.mailboxes.index');
        Route::post('/mailboxes', [MailboxController::class, 'store'])->middleware('api.scope:mail:write')->name('api.v1.mailboxes.store');
        Route::put('/mailboxes/{mailbox}/password', [MailboxController::class, 'password'])->middleware('api.scope:mail:write')->name('api.v1.mailboxes.password');
        Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->middleware('api.scope:mail:write')->name('api.v1.mailboxes.destroy');
    });
});
