<?php

use App\Http\Controllers\ApacheController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CommandJobController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\CronJobController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\PhpManagementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RedisCacheController;
use App\Http\Controllers\RoleManagementController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerPanelController;
use App\Http\Controllers\ServerTaskController;
use App\Http\Controllers\SshMemoryController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WebsiteController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,

    ]);
});
Route::get('/roundcube', [EmailController::class, 'webmailEntry'])->name('webmail.roundcube');
Route::post('/sso/webmail/consume', [SsoController::class, 'consumeWebmail'])->name('sso.webmail.consume');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/serverpanel', [ServerPanelController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('serverpanel.index');

    Route::get('/servers', [ServerController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('servers.index');
    Route::get('/servers/create', [ServerController::class, 'create'])
        ->middleware('role:admin|reseller')
        ->name('servers.create');
    Route::post('/servers', [ServerController::class, 'store'])
        ->middleware('role:admin|reseller')
        ->name('servers.store');
    Route::get('/servers/{server}', [ServerController::class, 'show'])
        ->middleware('role:admin|reseller')
        ->name('servers.show');
    Route::get('/servers/{server}/edit', [ServerController::class, 'edit'])
        ->middleware('role:admin|reseller')
        ->name('servers.edit');
    Route::patch('/servers/{server}', [ServerController::class, 'update'])
        ->middleware('role:admin|reseller')
        ->name('servers.update');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])
        ->middleware('role:admin|reseller')
        ->name('servers.destroy');
    Route::post('/servers/{server}/test-connection', [ServerController::class, 'testConnection'])
        ->middleware('role:admin|reseller')
        ->name('servers.test-connection');
    Route::post('/servers/{server}/scan', [ServerController::class, 'scanInventory'])
        ->middleware('role:admin|reseller')
        ->name('servers.scan');
    Route::get('/servers/{server}/terminal', [ServerController::class, 'terminal'])
        ->middleware('role:admin|reseller')
        ->name('servers.terminal');
    Route::get('/servers/{server}/commands', [CommandJobController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('servers.commands');

    Route::get('/commands', [CommandJobController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('commands.index');
    Route::post('/commands', [CommandJobController::class, 'store'])
        ->middleware('role:admin|reseller')
        ->name('commands.store');
    Route::get('/commands/{commandJob}', [CommandJobController::class, 'show'])
        ->middleware('role:admin|reseller')
        ->name('commands.show');
    Route::post('/commands/{commandJob}/approve', [CommandJobController::class, 'approve'])
        ->middleware('role:admin|reseller')
        ->name('commands.approve');
    Route::post('/commands/{commandJob}/cancel', [CommandJobController::class, 'cancel'])
        ->middleware('role:admin|reseller')
        ->name('commands.cancel');
    Route::post('/commands/{commandJob}/retry', [CommandJobController::class, 'retry'])
        ->middleware('role:admin|reseller')
        ->name('commands.retry');
    Route::post('/commands/{commandJob}/run-suggested-fix', [CommandJobController::class, 'runSuggestedFix'])
        ->middleware('role:admin|reseller')
        ->name('commands.run-suggested-fix');
    Route::get('/commands/{commandJob}/report', [CommandJobController::class, 'downloadReport'])
        ->middleware('role:admin|reseller')
        ->name('commands.report');

    Route::get('/server-tasks', [ServerTaskController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('server-tasks.index');
    Route::get('/server-tasks/create', [ServerTaskController::class, 'create'])
        ->middleware('role:admin|reseller')
        ->name('server-tasks.create');
    Route::post('/server-tasks', [ServerTaskController::class, 'store'])
        ->middleware('role:admin|reseller')
        ->name('server-tasks.store');
    Route::get('/server-tasks/{task}', [ServerTaskController::class, 'show'])
        ->middleware('role:admin|reseller')
        ->name('server-tasks.show');
    Route::post('/server-tasks/{task}/start', [ServerTaskController::class, 'start'])
        ->middleware('role:admin|reseller')
        ->name('server-tasks.start');
    Route::post('/server-tasks/{task}/cancel', [ServerTaskController::class, 'cancel'])
        ->middleware('role:admin|reseller')
        ->name('server-tasks.cancel');

    Route::get('/ssh-memories', [SshMemoryController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('ssh-memories.index');
    Route::post('/ssh-memories', [SshMemoryController::class, 'store'])
        ->middleware('role:admin|reseller')
        ->name('ssh-memories.store');
    Route::patch('/ssh-memories/{memory}', [SshMemoryController::class, 'update'])
        ->middleware('role:admin|reseller')
        ->name('ssh-memories.update');
    Route::delete('/ssh-memories/{memory}', [SshMemoryController::class, 'destroy'])
        ->middleware('role:admin|reseller')
        ->name('ssh-memories.destroy');
    Route::post('/ssh-memories/{memory}/mark-useful', [SshMemoryController::class, 'markUseful'])
        ->middleware('role:admin|reseller')
        ->name('ssh-memories.mark-useful');

    Route::get('/websites/create', [WebsiteController::class, 'create'])
        ->middleware('role:admin|reseller')
        ->name('websites.create');
    Route::post('/websites', [WebsiteController::class, 'store'])
        ->middleware('role:admin|reseller')
        ->name('websites.store');
    Route::get('/websites/parent-domains/search', [WebsiteController::class, 'searchParentDomains'])
        ->middleware('role:admin|reseller')
        ->name('websites.parent-domains.search');
    Route::get('/websites/{id}/edit', [WebsiteController::class, 'edit'])
        ->middleware('role:admin|reseller')
        ->name('websites.edit');
    Route::patch('/websites/{id}', [WebsiteController::class, 'update'])
        ->middleware('role:admin|reseller')
        ->name('websites.update');
    Route::delete('/websites/{id}', [WebsiteController::class, 'destroy'])
        ->middleware('role:admin|reseller')
        ->name('websites.destroy');
    Route::patch('/websites/{id}/status', [WebsiteController::class, 'updateStatus'])
        ->middleware('role:admin|reseller')
        ->name('websites.status.update');
    Route::get('/websites/{id}/manage', [WebsiteController::class, 'manage'])
        ->middleware('role:admin|reseller')
        ->name('websites.manage');
    Route::get('/websites/{id}/web-server', [WebsiteController::class, 'webServerManager'])
        ->middleware('role:admin|reseller')
        ->name('websites.web-server');
    Route::get('/websites/{id}/ssl', [WebsiteController::class, 'sslManager'])
        ->middleware('role:admin|reseller')
        ->name('websites.ssl');
    Route::post('/websites/{id}/ssl/issue', [WebsiteController::class, 'issueSsl'])
        ->middleware('role:admin|reseller')
        ->name('websites.ssl.issue');
    Route::get('/websites/{id}/usage', [WebsiteController::class, 'Usage'])
        ->middleware('role:admin|reseller')
        ->name('websites.usage');
    Route::post('/websites/{id}/vhost/sync', [WebsiteController::class, 'syncVhost'])
        ->middleware('role:admin|reseller')
        ->name('websites.vhost.sync');
    Route::post('/websites/{id}/project-cache/clear', [WebsiteController::class, 'clearProjectCache'])
        ->middleware('role:admin|reseller')
        ->name('websites.project-cache.clear');
    Route::get('/websites/{id}/wordpress', [WebsiteController::class, 'wordpressManager'])
        ->middleware('role:admin|reseller')
        ->name('websites.wordpress.manager');
    Route::post('/websites/{id}/wordpress/install', [WebsiteController::class, 'installWordPress'])
        ->middleware('role:admin|reseller')
        ->name('websites.wordpress.install');
    Route::get('/websites/{id}/preview/{path?}', [WebsiteController::class, 'preview'])
        ->middleware('role:admin|reseller')
        ->where('path', '.*')
        ->name('websites.preview');
    Route::get('/websites/{id}/redis-cache', [RedisCacheController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('websites.redis-cache.index');
    Route::post('/websites/{id}/redis-cache/clear', [RedisCacheController::class, 'clearWebsiteCache'])
        ->middleware('role:admin|reseller')
        ->name('websites.redis-cache.clear');
    Route::get('/websites/{id}/filemanager', [WebsiteController::class, 'fileManager'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager');
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
    Route::post('/websites/{id}/filemanager/folder', [WebsiteController::class, 'createFolder'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.folder.store');
    Route::post('/websites/{id}/filemanager/file', [WebsiteController::class, 'createFile'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.file.store');
    Route::patch('/websites/{id}/filemanager/file', [WebsiteController::class, 'saveFile'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.file.save');
    Route::post('/websites/{id}/filemanager/upload', [WebsiteController::class, 'uploadFile'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.upload');
    Route::patch('/websites/{id}/filemanager/permissions', [WebsiteController::class, 'changePermissions'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.permissions');
    Route::patch('/websites/{id}/filemanager/rename', [WebsiteController::class, 'renameItem'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.item.rename');
    Route::patch('/websites/{id}/filemanager/move', [WebsiteController::class, 'moveItems'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.item.move');
    Route::get('/websites/{id}/filemanager/download', [WebsiteController::class, 'downloadFile'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.file.download');
    Route::post('/websites/{id}/filemanager/zip', [WebsiteController::class, 'zipSelected'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.zip');
    Route::post('/websites/{id}/filemanager/unzip', [WebsiteController::class, 'unzipItem'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.unzip');
    Route::delete('/websites/{id}/filemanager/item', [WebsiteController::class, 'deleteItem'])
        ->middleware('role:admin|reseller')
        ->name('websites.filemanager.item.delete');
    Route::get('/websites/list', [WebsiteController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('websites.list');

    Route::get('/emails/create', [EmailController::class, 'create'])
        ->middleware('role:admin|reseller')
        ->name('emails.create');
    Route::post('/emails', [EmailController::class, 'store'])
        ->middleware('role:admin|reseller')
        ->name('emails.store');
    Route::get('/emails/{id}/edit', [EmailController::class, 'edit'])
        ->middleware('role:admin|reseller')
        ->name('emails.edit');
    Route::patch('/emails/{id}', [EmailController::class, 'update'])
        ->middleware('role:admin|reseller')
        ->name('emails.update');
    Route::delete('/emails/{id}', [EmailController::class, 'destroy'])
        ->middleware('role:admin|reseller')
        ->name('emails.destroy');
    Route::get('/emails/{id}/login', [EmailController::class, 'login'])
        ->middleware('role:admin|reseller')
        ->name('emails.login');
    Route::get('/emails/list', [EmailController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('emails.list');

    Route::get('/terminal', [TerminalController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('terminal.index');
    Route::post('/terminal/execute', [TerminalController::class, 'execute'])
        ->middleware('role:admin|reseller')
        ->name('terminal.execute');
    Route::get('/apache', [ApacheController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('apache.index');
    Route::post('/apache/action', [ApacheController::class, 'runAction'])
        ->middleware('role:admin|reseller')
        ->name('apache.action');
    Route::post('/apache/sync-shared-websites', [ApacheController::class, 'syncSharedWebsites'])
        ->middleware('role:admin|reseller')
        ->name('apache.sync-shared-websites');
    Route::get('/backups', [BackupController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('backups.index');
    Route::post('/backups/run', [BackupController::class, 'runNow'])
        ->middleware('role:admin|reseller')
        ->name('backups.run');
    Route::patch('/backups/settings', [BackupController::class, 'updateSettings'])
        ->middleware('role:admin|reseller')
        ->name('backups.settings.update');
    Route::get('/backups/{run}/{file}', [BackupController::class, 'download'])
        ->middleware('role:admin|reseller')
        ->where('run', '[0-9]{8}_[0-9]{6}')
        ->where('file', '[^/]+')
        ->name('backups.download');
    Route::delete('/backups/{run}', [BackupController::class, 'destroyRun'])
        ->middleware('role:admin|reseller')
        ->where('run', '[0-9]{8}_[0-9]{6}')
        ->name('backups.destroy');
    Route::get('/monitoring', [MonitoringController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('monitoring.index');
    Route::get('/monitoring/snapshot', [MonitoringController::class, 'snapshot'])
        ->middleware('role:admin|reseller')
        ->name('monitoring.snapshot');

    Route::get('/databases/create', [DatabaseController::class, 'create'])
        ->middleware('role:admin|reseller')
        ->name('databases.create');
    Route::post('/databases', [DatabaseController::class, 'store'])
        ->middleware('role:admin|reseller')
        ->name('databases.store');
    Route::get('/databases/{id}/edit', [DatabaseController::class, 'edit'])
        ->middleware('role:admin|reseller')
        ->name('databases.edit');
    Route::get('/databases/{id}/phpmyadmin', [DatabaseController::class, 'openPhpMyAdmin'])
        ->middleware('role:admin|reseller')
        ->name('databases.phpmyadmin');
    Route::patch('/databases/{id}', [DatabaseController::class, 'update'])
        ->middleware('role:admin|reseller')
        ->name('databases.update');
    Route::delete('/databases/{id}', [DatabaseController::class, 'destroy'])
        ->middleware('role:admin|reseller')
        ->name('databases.destroy');
    Route::get('/databases/list', [DatabaseController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('databases.list');

    Route::get('/dns/nameservers', [DnsController::class, 'nameservers'])
        ->middleware('role:admin|reseller')
        ->name('dns.nameservers');
    Route::post('/dns/nameservers', [DnsController::class, 'storeNameserver'])
        ->middleware('role:admin|reseller')
        ->name('dns.nameservers.store');
    Route::patch('/dns/nameservers/{id}', [DnsController::class, 'updateNameserver'])
        ->middleware('role:admin|reseller')
        ->name('dns.nameservers.update');
    Route::delete('/dns/nameservers/{id}', [DnsController::class, 'destroyNameserver'])
        ->middleware('role:admin|reseller')
        ->name('dns.nameservers.destroy');

    Route::get('/dns/zones', [DnsController::class, 'zones'])
        ->middleware('role:admin|reseller')
        ->name('dns.zones');
    Route::post('/dns/zones', [DnsController::class, 'storeZone'])
        ->middleware('role:admin|reseller')
        ->name('dns.zones.store');
    Route::patch('/dns/zones/{id}', [DnsController::class, 'updateZone'])
        ->middleware('role:admin|reseller')
        ->name('dns.zones.update');
    Route::delete('/dns/zones/{id}', [DnsController::class, 'destroyZone'])
        ->middleware('role:admin|reseller')
        ->name('dns.zones.destroy');
    Route::post('/dns/cloudflare/sync', [DnsController::class, 'syncCloudflare'])
        ->middleware('role:admin|reseller')
        ->name('dns.cloudflare.sync');

    Route::get('/dns/records', [DnsController::class, 'records'])
        ->middleware('role:admin|reseller')
        ->name('dns.records');
    Route::post('/dns/records', [DnsController::class, 'storeRecord'])
        ->middleware('role:admin|reseller')
        ->name('dns.records.store');
    Route::patch('/dns/records/{id}', [DnsController::class, 'updateRecord'])
        ->middleware('role:admin|reseller')
        ->name('dns.records.update');
    Route::delete('/dns/records/{id}', [DnsController::class, 'destroyRecord'])
        ->middleware('role:admin|reseller')
        ->name('dns.records.destroy');

    Route::get('/php/versions', [PhpManagementController::class, 'versions'])
        ->middleware('role:admin|reseller')
        ->name('php.versions');
    Route::get('/php/manager', [PhpManagementController::class, 'manager'])
        ->middleware('role:admin|reseller')
        ->name('php.manager');
    Route::patch('/php/versions', [PhpManagementController::class, 'updateVersions'])
        ->middleware('role:admin|reseller')
        ->name('php.versions.update');
    Route::get('/php/versions/check-installed', [PhpManagementController::class, 'checkInstalledVersions'])
        ->middleware('role:admin|reseller')
        ->name('php.versions.check-installed');
    Route::post('/php/versions/refresh', [PhpManagementController::class, 'refreshVersionsFromServer'])
        ->middleware('role:admin|reseller')
        ->name('php.versions.refresh');

    Route::get('/php/extensions', function () {
        return redirect()->route('php.manager', ['version' => request('version')]);
    })->middleware('role:admin|reseller')->name('php.extensions');
    Route::patch('/php/extensions', [PhpManagementController::class, 'updateExtensions'])
        ->middleware('role:admin|reseller')
        ->name('php.extensions.update');
    Route::post('/php/extensions/sync', [PhpManagementController::class, 'syncExtensionsFromServer'])
        ->middleware('role:admin|reseller')
        ->name('php.extensions.sync');

    Route::get('/php/config', function () {
        return redirect()->route('php.manager', ['version' => request('version')]);
    })->middleware('role:admin|reseller')->name('php.config');
    Route::patch('/php/config', [PhpManagementController::class, 'updateConfig'])
        ->middleware('role:admin|reseller')
        ->name('php.config.update');

    Route::redirect('/php/settings', '/php/config')
        ->middleware('role:admin|reseller')
        ->name('php.settings');

    Route::get('/security', [SecurityController::class, 'manager'])
        ->middleware('role:admin|reseller')
        ->name('security.manager');
    Route::post('/security/sync', [SecurityController::class, 'syncFromServer'])
        ->middleware('role:admin|reseller')
        ->name('security.sync');
    Route::patch('/security/firewall', [SecurityController::class, 'updateFirewall'])
        ->middleware('role:admin|reseller')
        ->name('security.firewall.update');
    Route::patch('/security/ssh', [SecurityController::class, 'updateSsh'])
        ->middleware('role:admin|reseller')
        ->name('security.ssh.update');

    Route::get('/admin', [UserManagementController::class, 'index'])
        ->middleware('role:admin')
        ->name('admin.panel');

    Route::get('/reseller', [UserManagementController::class, 'index'])
        ->middleware('role:admin|reseller')
        ->name('reseller.panel');

    Route::get('/user-panel', [UserManagementController::class, 'index'])
        ->middleware('role:admin|reseller|general|general_user')
        ->name('user.panel');
    Route::get('/users/manage', [UserManagementController::class, 'index'])
        ->middleware('role:admin|reseller|general|general_user')
        ->name('users.manage');
    Route::get('/users/manage/create', [UserManagementController::class, 'create'])
        ->middleware('role:admin|reseller')
        ->name('users.manage.create');
    Route::post('/users/manage', [UserManagementController::class, 'store'])
        ->middleware('role:admin|reseller')
        ->name('users.manage.store');
    Route::get('/users/manage/{user}/edit', [UserManagementController::class, 'edit'])
        ->middleware('role:admin|reseller')
        ->name('users.manage.edit');
    Route::patch('/users/manage/{user}', [UserManagementController::class, 'update'])
        ->middleware('role:admin|reseller')
        ->name('users.manage.update');
    Route::patch('/users/manage/{user}/suspension', [UserManagementController::class, 'updateSuspension'])
        ->middleware('role:admin|reseller')
        ->name('users.manage.suspension');
    Route::delete('/users/manage/{user}', [UserManagementController::class, 'destroy'])
        ->middleware('role:admin|reseller')
        ->name('users.manage.destroy');
    Route::get('/roles/manage', [RoleManagementController::class, 'index'])
        ->middleware('role:admin')
        ->name('roles.manage');
    Route::get('/roles/create', [RoleManagementController::class, 'create'])
        ->middleware('role:admin')
        ->name('roles.create');
    Route::get('/roles/manage/{role}/edit', [RoleManagementController::class, 'edit'])
        ->middleware('role:admin')
        ->name('roles.manage.edit');
    Route::post('/roles/manage', [RoleManagementController::class, 'store'])
        ->middleware('role:admin')
        ->name('roles.manage.store');
    Route::patch('/roles/manage/{role}', [RoleManagementController::class, 'update'])
        ->middleware('role:admin')
        ->name('roles.manage.update');
    Route::delete('/roles/manage/{role}', [RoleManagementController::class, 'destroy'])
        ->middleware('role:admin')
        ->name('roles.manage.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
