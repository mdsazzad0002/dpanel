<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\CronJobController;
use App\Http\Controllers\MigrationController;
use App\Http\Controllers\RedisCacheController;
use App\Http\Controllers\Website\WebsiteController;
use App\Http\Controllers\Website\WebsiteFileManagerController;
use App\Http\Controllers\Website\WebsiteGitController;
use App\Http\Controllers\Website\WebsiteManage\MainWebsiteController;
use App\Http\Controllers\Website\WebsiteOperationsController;
use App\Http\Controllers\Website\WebsiteSshKeyController;
use App\Http\Controllers\Website\WebsiteTerminalController;
use App\Http\Controllers\Website\WordpressController;
// Manage Website ===================================================================
use Illuminate\Support\Facades\Route;

Route::get('/websites/create', [MainWebsiteController::class, 'create'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.create');

Route::post('/websites', [MainWebsiteController::class, 'store'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.store');

// Manage Website

// Manage Alis Website =============================================================
use App\Http\Controllers\Website\WebsiteManage\AlisWebsiteController;

Route::get('/websites/alias/create', [AlisWebsiteController::class, 'create'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.alias.create');

// End Alis Website

Route::get('/websites/parent-domains/search', [WebsiteController::class, 'searchParentDomains'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.parent-domains.search');
Route::post('/websites/cache/reload', [WebsiteController::class, 'reloadGatewayCache'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.cache.reload');
Route::patch('/websites/{id}/ownership', [WebsiteController::class, 'updateOwnership'])
    ->middleware('role:admin|reseller')
    ->name('websites.ownership.update');

Route::patch('/websites/{id}/alias', [MainWebsiteController::class, 'updateAlias'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.alias.update');
Route::delete('/websites/{id}', [MainWebsiteController::class, 'destroy'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.destroy');
Route::patch('/websites/{id}/status', [WebsiteController::class, 'updateStatus'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.status.update');
Route::post('/websites/{id}/status/check', [WebsiteController::class, 'refreshRuntimeStatus'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.status.check');
Route::get('/websites/{id}/manage', [WebsiteOperationsController::class, 'manage'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.manage');
Route::get('/websites/{id}/quick-export', [WebsiteOperationsController::class, 'quickExportPage'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.quick-export.page');
Route::post('/websites/{id}/quick-export', [BackupController::class, 'quickExport'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:3,1'])
    ->name('websites.quick-export');
Route::get('/websites/{id}/ip-rules', [WebsiteOperationsController::class, 'ipRules'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.ip-rules.index');
Route::patch('/websites/{id}/runtime-settings', [WebsiteOperationsController::class, 'updateRuntimeSettings'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.update');
Route::get('/websites/{id}/git', [WebsiteGitController::class, 'index'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.git.index');
Route::put('/websites/{id}/git', [WebsiteGitController::class, 'store'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.git.store');
Route::post('/websites/{id}/git/run', [WebsiteGitController::class, 'run'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.git.run');
Route::get('/websites/{id}/ssh-key', [WebsiteSshKeyController::class, 'index'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.ssh-key.index');
Route::post('/websites/{id}/ssh-key', [WebsiteSshKeyController::class, 'generate'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:6,1'])->name('websites.ssh-key.generate');
Route::get('/websites/{id}/terminal', [WebsiteTerminalController::class, 'index'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.terminal.index');
Route::post('/websites/{id}/terminal', [WebsiteTerminalController::class, 'execute'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:30,1'])->name('websites.terminal.execute');
Route::post('/websites/{id}/terminal/session', [WebsiteTerminalController::class, 'session'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:10,1'])->name('websites.terminal.session');
Route::get('/websites/{id}/ssl', [WebsiteOperationsController::class, 'sslManager'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.ssl');
Route::post('/websites/{id}/ssl/issue', [WebsiteOperationsController::class, 'issueSsl'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.ssl.issue');
Route::patch('/websites/{id}/ssl/status', [WebsiteOperationsController::class, 'updateSslStatus'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.ssl.status.update');
Route::get('/websites/{id}/usage', [WebsiteOperationsController::class, 'Usage'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.usage');
Route::post('/websites/{id}/project-cache/clear', [WebsiteOperationsController::class, 'clearProjectCache'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.project-cache.clear');
Route::post('/websites/{id}/project-permissions/fix', [WebsiteOperationsController::class, 'fixProjectPermissions'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:10,1'])
    ->name('websites.project-permissions.fix');
Route::post('/websites/{id}/project-database/connect', [WebsiteOperationsController::class, 'connectProjectDatabase'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:10,1'])
    ->name('websites.project-database.connect');
Route::post('/websites/{id}/project-dependencies/install', [WebsiteOperationsController::class, 'installProjectDependencies'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:6,1'])
    ->name('websites.project-dependencies.install');
Route::post('/websites/{id}/project-storage-link', [WebsiteOperationsController::class, 'updateProjectStorageLink'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.project-storage-link.update');
Route::get('/websites/{id}/import', [MigrationController::class, 'websiteImport'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.import.index');
Route::post('/websites/{id}/import', [MigrationController::class, 'storeWebsiteImport'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:6,1'])->name('websites.import.store');
Route::post('/websites/{id}/import/{tracking}/chunks/{kind}', [MigrationController::class, 'uploadWebsiteImportChunk'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:240,1'])->whereUuid('tracking')->whereIn('kind', ['archive', 'database'])->name('websites.import.chunk');
Route::post('/websites/{id}/import/{tracking}/complete/{kind}', [MigrationController::class, 'completeWebsiteImportUpload'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->whereUuid('tracking')->whereIn('kind', ['archive', 'database'])->name('websites.import.complete');
Route::post('/websites/{id}/import/{tracking}/connect', [MigrationController::class, 'connectWebsiteImport'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->whereUuid('tracking')->name('websites.import.connect');
Route::get('/websites/{id}/import/{tracking}/status', [MigrationController::class, 'websiteImportStatus'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->whereUuid('tracking')->name('websites.import.status');
Route::post('/websites/{id}/ip-rules', [WebsiteOperationsController::class, 'storeIpRule'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:20,1'])
    ->name('websites.ip-rules.store');
Route::delete('/websites/{id}/ip-rules/{rule}', [WebsiteOperationsController::class, 'destroyIpRule'])
    ->middleware(['role_or_permission:admin|reseller|manage_websites', 'throttle:20,1'])
    ->name('websites.ip-rules.destroy');

// WordPress Management Routes
Route::get('/websites/{id}/wordpress', [WordpressController::class, 'wordpressManager'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.wordpress.manager');
Route::post('/websites/{id}/wordpress/install', [WordpressController::class, 'installWordPress'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.wordpress.install');

Route::get('/websites/{id}/redis-cache', [RedisCacheController::class, 'index'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.redis-cache.index');
Route::post('/websites/{id}/redis-cache/clear', [RedisCacheController::class, 'clearWebsiteCache'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.redis-cache.clear');
Route::post('/websites/{id}/redis-cache/configure', [RedisCacheController::class, 'configure'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.redis-cache.configure');
Route::post('/websites/{id}/redis-cache/revisions/{revision}/rollback', [RedisCacheController::class, 'rollback'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.redis-cache.rollback');
Route::get('/websites/{id}/alias-api', [RedisCacheController::class, 'aliasApiPage'])->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.alias-api.index');
Route::get('/websites/{id}/alias-api/settings', [RedisCacheController::class, 'aliasApiSettings'])->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.alias-api.settings');
Route::post('/websites/{id}/alias-api/rotate', [RedisCacheController::class, 'aliasApiRotate'])->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.alias-api.rotate');
Route::patch('/websites/{id}/alias-api', [RedisCacheController::class, 'aliasApiToggle'])->middleware('role_or_permission:admin|reseller|manage_websites')->name('websites.alias-api.toggle');
Route::get('/websites/{id}/filemanager', [WebsiteFileManagerController::class, 'fileManager'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager');
Route::patch('/websites/{id}/filemanager/settings', [WebsiteFileManagerController::class, 'updateFileManagerSettings'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.settings');
Route::get('/websites/{id}/cron-jobs', [CronJobController::class, 'index'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.cronjobs.index');
Route::post('/websites/{id}/cron-jobs', [CronJobController::class, 'store'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.cronjobs.store');
Route::patch('/websites/{id}/cron-jobs/{jobId}', [CronJobController::class, 'update'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.cronjobs.update');
Route::delete('/websites/{id}/cron-jobs/{jobId}', [CronJobController::class, 'destroy'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.cronjobs.destroy');
Route::post('/websites/{id}/filemanager/folder', [WebsiteFileManagerController::class, 'createFolder'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.folder.store');
Route::post('/websites/{id}/filemanager/file', [WebsiteFileManagerController::class, 'createFile'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.file.store');
Route::patch('/websites/{id}/filemanager/file', [WebsiteFileManagerController::class, 'saveFile'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.file.save');
Route::post('/websites/{id}/filemanager/upload', [WebsiteFileManagerController::class, 'uploadFile'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.upload');
Route::patch('/websites/{id}/filemanager/permissions', [WebsiteFileManagerController::class, 'changePermissions'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.permissions');
Route::patch('/websites/{id}/filemanager/rename', [WebsiteFileManagerController::class, 'renameItem'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.item.rename');
Route::patch('/websites/{id}/filemanager/move', [WebsiteFileManagerController::class, 'moveItems'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.item.move');
Route::get('/websites/{id}/filemanager/download', [WebsiteFileManagerController::class, 'downloadFile'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.file.download');
Route::post('/websites/{id}/filemanager/zip', [WebsiteFileManagerController::class, 'zipSelected'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.zip');
Route::post('/websites/{id}/filemanager/unzip', [WebsiteFileManagerController::class, 'unzipItem'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.unzip');
Route::delete('/websites/{id}/filemanager/item', [WebsiteFileManagerController::class, 'deleteItem'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.filemanager.item.delete');
Route::get('/websites/list', [WebsiteController::class, 'index'])
    ->middleware('role_or_permission:admin|reseller|manage_websites')
    ->name('websites.list');
