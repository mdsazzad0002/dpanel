<?php

use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\UserPanelController;
use App\Http\Controllers\WebsiteController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/websites/create', [WebsiteController::class, 'create'])
        ->middleware('role:super_admin|reseller')
        ->name('websites.create');
    Route::post('/websites', [WebsiteController::class, 'store'])
        ->middleware('role:super_admin|reseller')
        ->name('websites.store');
    Route::get('/websites/{id}/edit', [WebsiteController::class, 'edit'])
        ->middleware('role:super_admin|reseller')
        ->name('websites.edit');
    Route::patch('/websites/{id}', [WebsiteController::class, 'update'])
        ->middleware('role:super_admin|reseller')
        ->name('websites.update');
    Route::delete('/websites/{id}', [WebsiteController::class, 'destroy'])
        ->middleware('role:super_admin|reseller')
        ->name('websites.destroy');
    Route::get('/websites/{id}/manage', [WebsiteController::class, 'manage'])
        ->middleware('role:super_admin|reseller')
        ->name('websites.manage');
    Route::get('/websites/list', [WebsiteController::class, 'index'])
        ->middleware('role:super_admin|reseller')
        ->name('websites.list');

    Route::get('/emails/create', function () {
        return Inertia::render('CreateEmail');
    })->middleware('role:super_admin|reseller')->name('emails.create');

    Route::get('/emails/list', function () {
        return Inertia::render('ListEmails');
    })->middleware('role:super_admin|reseller')->name('emails.list');

    Route::get('/terminal', [TerminalController::class, 'index'])
        ->middleware('role:super_admin|reseller')
        ->name('terminal.index');
    Route::post('/terminal/execute', [TerminalController::class, 'execute'])
        ->middleware('role:super_admin|reseller')
        ->name('terminal.execute');

    Route::get('/databases/create', [DatabaseController::class, 'create'])
        ->middleware('role:super_admin|reseller')
        ->name('databases.create');
    Route::post('/databases', [DatabaseController::class, 'store'])
        ->middleware('role:super_admin|reseller')
        ->name('databases.store');
    Route::get('/databases/{id}/edit', [DatabaseController::class, 'edit'])
        ->middleware('role:super_admin|reseller')
        ->name('databases.edit');
    Route::patch('/databases/{id}', [DatabaseController::class, 'update'])
        ->middleware('role:super_admin|reseller')
        ->name('databases.update');
    Route::delete('/databases/{id}', [DatabaseController::class, 'destroy'])
        ->middleware('role:super_admin|reseller')
        ->name('databases.destroy');
    Route::get('/databases/list', [DatabaseController::class, 'index'])
        ->middleware('role:super_admin|reseller')
        ->name('databases.list');

    Route::get('/phpmyadmin', function () {
        return Inertia::render('PhpMyAdminPanel');
    })->middleware('role:super_admin|reseller')->name('phpmyadmin.panel');

    Route::get('/dns/nameservers', function () {
        return Inertia::render('DnsNameservers');
    })->middleware('role:super_admin|reseller')->name('dns.nameservers');

    Route::get('/dns/zones', function () {
        return Inertia::render('DnsZones');
    })->middleware('role:super_admin|reseller')->name('dns.zones');

    Route::get('/dns/records', function () {
        return Inertia::render('DnsRecords');
    })->middleware('role:super_admin|reseller')->name('dns.records');

    Route::get('/php/versions', function () {
        return Inertia::render('PhpVersions');
    })->middleware('role:super_admin|reseller')->name('php.versions');

    Route::get('/php/settings', function () {
        return Inertia::render('PhpSettings');
    })->middleware('role:super_admin|reseller')->name('php.settings');

    Route::get('/packages/create', [PackageController::class, 'create'])
        ->middleware('role:super_admin|reseller')
        ->name('packages.create');
    Route::post('/packages', [PackageController::class, 'store'])
        ->middleware('role:super_admin|reseller')
        ->name('packages.store');
    Route::get('/packages/{package}/edit', [PackageController::class, 'edit'])
        ->middleware('role:super_admin|reseller')
        ->name('packages.edit');
    Route::patch('/packages/{package}', [PackageController::class, 'update'])
        ->middleware('role:super_admin|reseller')
        ->name('packages.update');
    Route::delete('/packages/{package}', [PackageController::class, 'destroy'])
        ->middleware('role:super_admin|reseller')
        ->name('packages.destroy');
    Route::get('/packages/list', [PackageController::class, 'index'])
        ->middleware('role:super_admin|reseller')
        ->name('packages.list');

    Route::get('/admin', function () {
        return Inertia::render('AdminPanel');
    })->middleware('role:super_admin')->name('admin.panel');

    Route::get('/reseller', function () {
        return Inertia::render('ResellerPanel');
    })->middleware('role:super_admin|reseller')->name('reseller.panel');

    Route::get('/user-panel', [UserPanelController::class, 'show'])
        ->middleware('role:super_admin|reseller|general_user')
        ->name('user.panel');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
