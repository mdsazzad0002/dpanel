<?php

use App\Http\Controllers\CronJobController;
use App\Http\Controllers\RedisCacheController;
use App\Http\Controllers\Website\WebsiteController;
use App\Http\Controllers\Website\WebsiteFileManagerController;
use App\Http\Controllers\Website\WebsiteManage\MainWebsiteController;
use App\Http\Controllers\Website\WebsiteOperationsController;
use App\Http\Controllers\Website\WebsiteGitController;
use App\Http\Controllers\Website\WebsiteSshKeyController;
use App\Http\Controllers\Website\WebsiteTerminalController;
use App\Http\Controllers\Website\WordpressController;
// Manage Website ===================================================================
use Illuminate\Support\Facades\Route;

Route::get('/websites/create', [MainWebsiteController::class, 'create'])
    ->middleware('role:admin|reseller')
    ->name('websites.create');

Route::post('/websites', [MainWebsiteController::class, 'store'])
    ->middleware('role:admin|reseller')
    ->name('websites.store');

// Manage Website

// Manage Alis Website =============================================================
use App\Http\Controllers\Website\WebsiteManage\AlisWebsiteController;

Route::get('/websites/alias/create', [AlisWebsiteController::class, 'create'])
    ->middleware('role:admin|reseller')
    ->name('websites.alias.create');

// End Alis Website

Route::get('/websites/parent-domains/search', [WebsiteController::class, 'searchParentDomains'])
    ->middleware('role:admin|reseller')
    ->name('websites.parent-domains.search');

Route::get('/websites/{id}/edit', [WebsiteController::class, 'edit'])
    ->middleware('role:admin|reseller')
    ->name('websites.edit');
Route::patch('/websites/{id}', [WebsiteController::class, 'update'])
    ->middleware('role:admin|reseller')
    ->name('websites.update');
Route::patch('/websites/{id}/alias', [MainWebsiteController::class, 'updateAlias'])
    ->middleware('role:admin|reseller')
    ->name('websites.alias.update');
Route::delete('/websites/{id}', [MainWebsiteController::class, 'destroy'])
    ->middleware('role:admin|reseller')
    ->name('websites.destroy');
Route::patch('/websites/{id}/status', [WebsiteController::class, 'updateStatus'])
    ->middleware('role:admin|reseller')
    ->name('websites.status.update');
Route::post('/websites/{id}/status/check', [WebsiteController::class, 'refreshRuntimeStatus'])
    ->middleware('role:admin|reseller')
    ->name('websites.status.check');
Route::get('/websites/{id}/manage', [WebsiteOperationsController::class, 'manage'])
    ->middleware('role:admin|reseller')
    ->name('websites.manage');
Route::get('/websites/{id}/git', [WebsiteGitController::class, 'index'])
    ->middleware('role:admin|reseller')->name('websites.git.index');
Route::put('/websites/{id}/git', [WebsiteGitController::class, 'store'])
    ->middleware('role:admin|reseller')->name('websites.git.store');
Route::post('/websites/{id}/git/run', [WebsiteGitController::class, 'run'])
    ->middleware('role:admin|reseller')->name('websites.git.run');
Route::get('/websites/{id}/ssh-key', [WebsiteSshKeyController::class, 'index'])
    ->middleware('role:admin|reseller')->name('websites.ssh-key.index');
Route::post('/websites/{id}/ssh-key', [WebsiteSshKeyController::class, 'generate'])
    ->middleware(['role:admin|reseller', 'throttle:6,1'])->name('websites.ssh-key.generate');
Route::get('/websites/{id}/terminal', [WebsiteTerminalController::class, 'index'])
    ->middleware('role:admin|reseller')->name('websites.terminal.index');
Route::post('/websites/{id}/terminal', [WebsiteTerminalController::class, 'execute'])
    ->middleware(['role:admin|reseller', 'throttle:30,1'])->name('websites.terminal.execute');
Route::post('/websites/{id}/terminal/session', [WebsiteTerminalController::class, 'session'])
    ->middleware(['role:admin|reseller', 'throttle:10,1'])->name('websites.terminal.session');
Route::get('/websites/{id}/ssl', [WebsiteOperationsController::class, 'sslManager'])
    ->middleware('role:admin|reseller')
    ->name('websites.ssl');
Route::post('/websites/{id}/ssl/issue', [WebsiteOperationsController::class, 'issueSsl'])
    ->middleware('role:admin|reseller')
    ->name('websites.ssl.issue');
Route::patch('/websites/{id}/ssl/status', [WebsiteOperationsController::class, 'updateSslStatus'])
    ->middleware('role:admin|reseller')
    ->name('websites.ssl.status.update');
Route::get('/websites/{id}/usage', [WebsiteOperationsController::class, 'Usage'])
    ->middleware('role:admin|reseller')
    ->name('websites.usage');
Route::post('/websites/{id}/project-cache/clear', [WebsiteOperationsController::class, 'clearProjectCache'])
    ->middleware('role:admin|reseller')
    ->name('websites.project-cache.clear');
Route::post('/websites/{id}/project-permissions/fix', [WebsiteOperationsController::class, 'fixProjectPermissions'])
    ->middleware(['role:admin|reseller', 'throttle:10,1'])
    ->name('websites.project-permissions.fix');
Route::post('/websites/{id}/project-database/connect', [WebsiteOperationsController::class, 'connectProjectDatabase'])
    ->middleware(['role:admin|reseller', 'throttle:10,1'])
    ->name('websites.project-database.connect');
Route::post('/websites/{id}/project-dependencies/install', [WebsiteOperationsController::class, 'installProjectDependencies'])
    ->middleware(['role:admin|reseller', 'throttle:6,1'])
    ->name('websites.project-dependencies.install');
Route::post('/websites/{id}/project-storage-link', [WebsiteOperationsController::class, 'updateProjectStorageLink'])
    ->middleware('role:admin|reseller')
    ->name('websites.project-storage-link.update');
Route::post('/websites/{id}/ip-rules', [WebsiteOperationsController::class, 'storeIpRule'])
    ->middleware(['role:admin|reseller', 'throttle:20,1'])
    ->name('websites.ip-rules.store');
Route::delete('/websites/{id}/ip-rules/{rule}', [WebsiteOperationsController::class, 'destroyIpRule'])
    ->middleware(['role:admin|reseller', 'throttle:20,1'])
    ->name('websites.ip-rules.destroy');

// WordPress Management Routes
Route::get('/websites/{id}/wordpress', [WordpressController::class, 'wordpressManager'])
    ->middleware('role:admin|reseller')
    ->name('websites.wordpress.manager');
Route::post('/websites/{id}/wordpress/install', [WordpressController::class, 'installWordPress'])
    ->middleware('role:admin|reseller')
    ->name('websites.wordpress.install');

Route::get('/websites/{id}/redis-cache', [RedisCacheController::class, 'index'])
    ->middleware('role:admin|reseller')
    ->name('websites.redis-cache.index');
Route::post('/websites/{id}/redis-cache/clear', [RedisCacheController::class, 'clearWebsiteCache'])
    ->middleware('role:admin|reseller')
    ->name('websites.redis-cache.clear');
Route::post('/websites/{id}/redis-cache/configure', [RedisCacheController::class, 'configure'])
    ->middleware('role:admin|reseller')->name('websites.redis-cache.configure');
Route::post('/websites/{id}/redis-cache/revisions/{revision}/rollback', [RedisCacheController::class, 'rollback'])
    ->middleware('role:admin|reseller')->name('websites.redis-cache.rollback');
Route::get('/websites/{id}/alias-api', [RedisCacheController::class, 'aliasApiPage'])->middleware('role:admin|reseller')->name('websites.alias-api.index');
Route::get('/websites/{id}/alias-api/settings', [RedisCacheController::class, 'aliasApiSettings'])->middleware('role:admin|reseller')->name('websites.alias-api.settings');
Route::post('/websites/{id}/alias-api/rotate', [RedisCacheController::class, 'aliasApiRotate'])->middleware('role:admin|reseller')->name('websites.alias-api.rotate');
Route::patch('/websites/{id}/alias-api', [RedisCacheController::class, 'aliasApiToggle'])->middleware('role:admin|reseller')->name('websites.alias-api.toggle');
Route::get('/websites/{id}/filemanager', [WebsiteFileManagerController::class, 'fileManager'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager');
Route::patch('/websites/{id}/filemanager/settings', [WebsiteFileManagerController::class, 'updateFileManagerSettings'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.settings');
Route::get('/websites/{id}/cron-jobs', [CronJobController::class, 'index'])
    ->middleware('role:admin|reseller')
    ->name('websites.cronjobs.index');
Route::post('/websites/{id}/cron-jobs', [CronJobController::class, 'store'])
    ->middleware('role:admin|reseller')
    ->name('websites.cronjobs.store');
Route::patch('/websites/{id}/cron-jobs/{jobId}', [CronJobController::class, 'update'])
    ->middleware('role:admin|reseller')
    ->name('websites.cronjobs.update');
Route::delete('/websites/{id}/cron-jobs/{jobId}', [CronJobController::class, 'destroy'])
    ->middleware('role:admin|reseller')
    ->name('websites.cronjobs.destroy');
Route::post('/websites/{id}/filemanager/folder', [WebsiteFileManagerController::class, 'createFolder'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.folder.store');
Route::post('/websites/{id}/filemanager/file', [WebsiteFileManagerController::class, 'createFile'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.file.store');
Route::patch('/websites/{id}/filemanager/file', [WebsiteFileManagerController::class, 'saveFile'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.file.save');
Route::post('/websites/{id}/filemanager/upload', [WebsiteFileManagerController::class, 'uploadFile'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.upload');
Route::patch('/websites/{id}/filemanager/permissions', [WebsiteFileManagerController::class, 'changePermissions'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.permissions');
Route::patch('/websites/{id}/filemanager/rename', [WebsiteFileManagerController::class, 'renameItem'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.item.rename');
Route::patch('/websites/{id}/filemanager/move', [WebsiteFileManagerController::class, 'moveItems'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.item.move');
Route::get('/websites/{id}/filemanager/download', [WebsiteFileManagerController::class, 'downloadFile'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.file.download');
Route::post('/websites/{id}/filemanager/zip', [WebsiteFileManagerController::class, 'zipSelected'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.zip');
Route::post('/websites/{id}/filemanager/unzip', [WebsiteFileManagerController::class, 'unzipItem'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.unzip');
Route::delete('/websites/{id}/filemanager/item', [WebsiteFileManagerController::class, 'deleteItem'])
    ->middleware('role:admin|reseller')
    ->name('websites.filemanager.item.delete');
Route::get('/websites/list', [WebsiteController::class, 'index'])
    ->middleware('role:admin|reseller')
    ->name('websites.list');
