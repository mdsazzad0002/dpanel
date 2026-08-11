<?php

use App\Http\Controllers\Auth\TelegramWebhookController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\MailClientController;
use App\Http\Controllers\MailPlanController;
use App\Http\Controllers\MigrationController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\PanelSearchController;
use App\Http\Controllers\PhpManagementController;
use App\Http\Controllers\PhpMyAdmin\PhpMyAdminController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RedisCacheController;
use App\Http\Controllers\RoleManagementController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerTaskController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WebsiteTrashBackupController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (Auth::check() && session('panel_session_token')) {
        return redirect()->route('dashboard', [
            'token' => session('panel_session_token'),
        ]);
    }

    return redirect()->route('login');
});

Route::get('/cpsess{token}/login', function () {
    // Viewing the login screen must not revoke the current token. Rotation
    // happens only after the user explicitly submits the login form.
    return redirect()->route('login');
})->where('token', '[0-9a-fA-F]{64}');

Route::get('/init', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
        'installerBaseUrl' => rtrim((string) url('/'), '/'),
        'defaultPanelDomain' => (string) (parse_url((string) config('serverpanel.panel_domain', ''), PHP_URL_HOST)
            ?: parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST)
            ?: config('serverpanel.panel_domain', 'localhost')),
        'defaultServerBaseDir' => (string) config('app.server_base_dir', ''),
        'defaultDbName' => (string) env('DB_DATABASE', 'serverpanel'),
        'defaultDbUser' => (string) env('DB_USERNAME', 'serverpanel'),
        'defaultDbHost' => (string) env('DB_HOST', '127.0.0.1'),
        'defaultDbPort' => (string) env('DB_PORT', '3306'),
        'defaultPanelEmail' => (string) env('MAIL_FROM_ADDRESS', 'admin@example.com'),

    ]);
})->name('init.docs');

Route::middleware(['panel.session'])->group(function (): void {
    Route::match(['get', 'post'], '/webmail', [EmailController::class, 'webmailEntry'])
        ->name('webmail.mailbox');
});

Route::post('/sso/webmail/consume', [SsoController::class, 'consumeWebmail'])
    ->name('sso.webmail.consume');

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'store'])
    ->name('telegram.webhook');
Route::post('/api/v1/alias', [RedisCacheController::class, 'aliasApiHandle'])->middleware('throttle:30,1')->name('api.alias');
Route::get('/telegram/webhook-url', [TelegramWebhookController::class, 'url'])
    ->name('telegram.webhook-url');

Route::prefix('cpsess{token}')
    ->where(['token' => '[0-9a-fA-F]{64}'])
    ->middleware(['panel.session', 'auth'])
    ->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');

        Route::get('/search', [PanelSearchController::class, 'index'])
            ->name('panel.search');

        Route::middleware('auth')->group(function () {
            Route::redirect('/serverpanel', '/servers')
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

            Route::redirect('/commands', '/servers');
            Route::redirect('/commands/{any}', '/servers')->where('any', '.*');
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

            Route::get('/emails/create', [EmailController::class, 'create'])
                ->middleware('role_or_permission:admin|reseller|manage_email')
                ->name('emails.create');
            Route::post('/emails', [EmailController::class, 'store'])
                ->middleware('role_or_permission:admin|reseller|manage_email')
                ->name('emails.store');
            Route::get('/emails/{id}/edit', [EmailController::class, 'edit'])
                ->middleware('role_or_permission:admin|reseller|manage_email')
                ->name('emails.edit');
            Route::patch('/emails/{id}', [EmailController::class, 'update'])
                ->middleware('role_or_permission:admin|reseller|manage_email')
                ->name('emails.update');
            Route::delete('/emails/{id}', [EmailController::class, 'destroy'])
                ->middleware('role_or_permission:admin|reseller|manage_email')
                ->name('emails.destroy');

            Route::get('/mail/{id}', [MailClientController::class, 'show'])
                ->middleware('role:admin|reseller')
                ->name('mailbox.open');

            Route::get('/mail/{id}/data', [MailClientController::class, 'data'])
                ->middleware('role:admin|reseller')
                ->name('mailbox.data');

            Route::post('/mail/{id}/send', [MailClientController::class, 'send'])
                ->middleware('role:admin|reseller')
                ->name('mailbox.send');

            Route::post('/mail/{id}/delete', [MailClientController::class, 'delete'])
                ->middleware('role:admin|reseller')
                ->name('mailbox.delete-message');

            Route::get('/emails/{id}/login', function ($id) {
                return redirect()->route('mailbox.open', [
                    'id' => $id,
                    'token' => request('token'),
                ]);
            })
                ->middleware('role:admin|reseller')
                ->name('emails.login');

            Route::get('/emails/{id}/login/data', [MailClientController::class, 'data'])
                ->middleware('role:admin|reseller')
                ->name('emails.data');

            Route::post('/emails/{id}/login/send', [MailClientController::class, 'send'])
                ->middleware('role:admin|reseller')
                ->name('emails.send');

            Route::post('/emails/{id}/login/delete', [MailClientController::class, 'delete'])
                ->middleware('role:admin|reseller')
                ->name('emails.delete-message');

            Route::get('/emails/list', [EmailController::class, 'index'])
                ->middleware('role_or_permission:admin|reseller|manage_email')
                ->name('emails.list');

            Route::post('/mail/{id}/mark-read', [MailClientController::class, 'markRead'])
                ->middleware('role:admin|reseller')
                ->name('mailbox.mark-read');

            Route::redirect('/mail-plans', '/packages');
            Route::get('/packages', [MailPlanController::class, 'index'])
                ->middleware('role_or_permission:admin|superadmin|reseller|manage_packages')
                ->name('packages.index');
            Route::get('/packages/create', [MailPlanController::class, 'create'])
                ->middleware('role_or_permission:admin|superadmin|reseller|manage_packages')
                ->name('packages.create');
            Route::post('/packages', [MailPlanController::class, 'store'])
                ->middleware('role_or_permission:admin|superadmin|reseller|manage_packages')
                ->name('packages.store');
            Route::get('/packages/{id}/edit', [MailPlanController::class, 'edit'])
                ->middleware('role_or_permission:admin|superadmin|reseller|manage_packages')
                ->name('packages.edit');
            Route::patch('/packages/{id}', [MailPlanController::class, 'update'])
                ->middleware('role_or_permission:admin|superadmin|reseller|manage_packages')
                ->name('packages.update');
            Route::delete('/packages/{id}', [MailPlanController::class, 'destroy'])
                ->middleware('role_or_permission:admin|superadmin|reseller|manage_packages')
                ->name('packages.destroy');

            Route::get('/backups', [BackupController::class, 'index'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->name('backups.index');

            Route::get('/migrations', [MigrationController::class, 'index'])->middleware('role:admin|reseller')->name('migrations.index');
            Route::get('/migrations/cpanel', [MigrationController::class, 'cpanel'])->middleware('role:admin|reseller')->name('migrations.cpanel');
            Route::get('/migrations/cyberpanel-ssh', [MigrationController::class, 'cyberpanelSsh'])->middleware('role:admin|reseller')->name('migrations.cyberpanel-ssh');
            Route::post('/migrations/cyberpanel-ssh/inspect', [MigrationController::class, 'inspectCyberpanelSsh'])->middleware(['role:admin|reseller', 'throttle:10,1'])->name('migrations.cyberpanel-ssh.inspect');
            Route::post('/migrations', [MigrationController::class, 'store'])->middleware('role:admin|reseller')->name('migrations.store');
            Route::post('/migrations/{migrationImport}/restore', [MigrationController::class, 'restore'])->middleware('role:admin|reseller')->name('migrations.restore');
            Route::delete('/migrations/{migrationImport}', [MigrationController::class, 'destroy'])->middleware('role:admin|reseller')->name('migrations.destroy');
            Route::post('/backups/run', [BackupController::class, 'runNow'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->name('backups.run');
            Route::get('/backups/data', [BackupController::class, 'data'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->name('backups.data');
            Route::get('/backups/scp', [BackupController::class, 'scp'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->name('backups.scp');
            Route::patch('/backups/scp/settings', [BackupController::class, 'updateScpSettings'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->name('backups.scp.settings.update');
            Route::patch('/backups/settings', [BackupController::class, 'updateSettings'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->name('backups.settings.update');
            Route::get('/backups/{run}/fetch/{encoded}', [BackupController::class, 'downloadEncoded'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->where('run', '[0-9]{8}_[0-9]{6}')
                ->where('encoded', '[A-Za-z0-9_-]+')
                ->name('backups.download');
            Route::get('/backups/{run}/download', [BackupController::class, 'downloadFromQuery'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->where('run', '[0-9]{8}_[0-9]{6}')
                ->name('backups.download.query');
            Route::get('/backups/{run}/{file}', [BackupController::class, 'download'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->where('run', '[0-9]{8}_[0-9]{6}')
                ->where('file', '[^/]+')
                ->name('backups.download.legacy');
            Route::delete('/backups/{run}', [BackupController::class, 'destroyRun'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->where('run', '[0-9]{8}_[0-9]{6}')
                ->name('backups.destroy');
            Route::post('/backups/{run}/restore/{encoded}', [BackupController::class, 'restore'])
                ->middleware('role_or_permission:admin|reseller|manage_backups')
                ->where('run', '[0-9]{8}_[0-9]{6}')
                ->where('encoded', '[A-Za-z0-9_-]+')
                ->name('backups.restore');
            Route::get('/trash-backups', [WebsiteTrashBackupController::class, 'index'])
                ->middleware('role:admin|reseller|general|general_user')
                ->name('trash-backups.index');
            Route::patch('/trash-backups/retention', [WebsiteTrashBackupController::class, 'updateRetention'])
                ->middleware('role:admin|reseller')
                ->name('trash-backups.retention.update');
            Route::get('/trash-backups/{id}/download', [WebsiteTrashBackupController::class, 'download'])
                ->middleware('role:admin|reseller|general|general_user')
                ->whereUuid('id')
                ->name('trash-backups.download');
            Route::post('/trash-backups/{id}/restore', [WebsiteTrashBackupController::class, 'restore'])
                ->middleware('role:admin|reseller|general|general_user')
                ->whereUuid('id')
                ->name('trash-backups.restore');
            Route::get('/monitoring', [MonitoringController::class, 'index'])
                ->middleware('role:admin|reseller')
                ->name('monitoring.index');
            Route::get('/monitoring/snapshot', [MonitoringController::class, 'snapshot'])
                ->middleware('role:admin|reseller')
                ->name('monitoring.snapshot');

            Route::get('/databases/create', [DatabaseController::class, 'create'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('databases.create');
            Route::post('/databases', [DatabaseController::class, 'store'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('databases.store');
            Route::get('/databases/{id}/edit', [DatabaseController::class, 'edit'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('databases.edit');

            Route::get('/phpmyadmin', [PhpMyAdminController::class, 'index'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.index');
            Route::get('/phpmyadmin/about', [PhpMyAdminController::class, 'about'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.about');
            Route::get('/phpmyadmin/sql', [PhpMyAdminController::class, 'sqlPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.sql');
            Route::get('/phpmyadmin/databases-page', [PhpMyAdminController::class, 'databasesPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.databases');
            Route::get('/phpmyadmin/transfer', [PhpMyAdminController::class, 'transferPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.transfer');
            Route::get('/phpmyadmin/status', [PhpMyAdminController::class, 'statusPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.status');
            Route::get('/phpmyadmin/user-accounts', [PhpMyAdminController::class, 'userAccountsPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.user-accounts');
            Route::get('/phpmyadmin/settings', [PhpMyAdminController::class, 'settingsPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.settings');
            Route::get('/phpmyadmin/replication', [PhpMyAdminController::class, 'replicationPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.replication');
            Route::get('/phpmyadmin/variables', [PhpMyAdminController::class, 'variablesPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.variables');
            Route::get('/phpmyadmin/charsets', [PhpMyAdminController::class, 'charsetsPage'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.page.charsets');
            Route::get('/phpmyadmin/databases', [PhpMyAdminController::class, 'databases'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.databases');
            Route::get('/phpmyadmin/databases/{database}', [PhpMyAdminController::class, 'database'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where('database', '[A-Za-z0-9_]+')
                ->name('phpmyadmin.database');
            Route::get('/phpmyadmin/databases/{database}/tables/{table}', [PhpMyAdminController::class, 'table'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where(['database' => '[A-Za-z0-9_]+', 'table' => '[A-Za-z0-9_]+'])
                ->name('phpmyadmin.table');
            Route::delete('/phpmyadmin/databases/{database}/tables/{table}', [PhpMyAdminController::class, 'destroyTable'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where(['database' => '[A-Za-z0-9_]+', 'table' => '[A-Za-z0-9_]+'])
                ->name('phpmyadmin.table.destroy');
            Route::post('/phpmyadmin/databases/{database}/tables/{table}/empty', [PhpMyAdminController::class, 'emptyTable'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where(['database' => '[A-Za-z0-9_]+', 'table' => '[A-Za-z0-9_]+'])
                ->name('phpmyadmin.table.empty');
            Route::post('/phpmyadmin/databases/{database}/tables/{table}/rename', [PhpMyAdminController::class, 'renameTable'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where(['database' => '[A-Za-z0-9_]+', 'table' => '[A-Za-z0-9_]+'])
                ->name('phpmyadmin.table.rename');
            Route::post('/phpmyadmin/databases/{database}/tables/{table}/structure', [PhpMyAdminController::class, 'alterTableStructure'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where(['database' => '[A-Za-z0-9_]+', 'table' => '[A-Za-z0-9_]+'])
                ->name('phpmyadmin.table.structure.update');
            Route::post('/phpmyadmin/databases/{database}/tables', [PhpMyAdminController::class, 'createTable'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where(['database' => '[A-Za-z0-9_]+'])
                ->name('phpmyadmin.table.create');
            Route::post('/phpmyadmin/query', [PhpMyAdminController::class, 'execute'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.execute');
            Route::post('/phpmyadmin/export', [PhpMyAdminController::class, 'export'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.export');
            Route::post('/phpmyadmin/import', [PhpMyAdminController::class, 'import'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('phpmyadmin.import');

            Route::get('/phpmyadmin/root-autologin', [DatabaseController::class, 'openPhpMyAdminRootGlobal'])
                ->middleware('role:admin')
                ->name('phpmyadmin.root-autologin');

            Route::get('/databases/{id}/phpmyadmin/autologin', [DatabaseController::class, 'openPhpMyAdmin'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where('id', '[^/]+')
                ->name('databases.phpmyadmin.autologin');

            Route::get('/databases/{id}/phpmyadmin/root-autologin', [DatabaseController::class, 'openPhpMyAdminRoot'])
                ->middleware('role:admin')
                ->where('id', '[^/]+')
                ->name('databases.phpmyadmin.root-autologin');

            Route::redirect('/databases/{id}/phpmyadmin/{path?}', '/phpmyadmin')
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->where('path', '.*')
                ->where('id', '[^/]+')
                ->name('databases.phpmyadmin');

            include __DIR__.'/website.php';

            Route::patch('/databases/{id}', [DatabaseController::class, 'update'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('databases.update');
            Route::delete('/databases/{id}', [DatabaseController::class, 'destroy'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('databases.destroy');
            Route::get('/databases/list', [DatabaseController::class, 'index'])
                ->middleware('role_or_permission:admin|reseller|manage_databases')
                ->name('databases.list');

            Route::get('/dns/nameservers', [DnsController::class, 'nameservers'])
                ->middleware('role_or_permission:admin|reseller|manage_dns')
                ->name('dns.nameservers');
            Route::post('/dns/nameservers', [DnsController::class, 'storeNameserver'])
                ->middleware('role_or_permission:admin|reseller|manage_dns')
                ->name('dns.nameservers.store');
            Route::patch('/dns/nameservers/{id}', [DnsController::class, 'updateNameserver'])
                ->middleware('role_or_permission:admin|reseller|manage_dns')
                ->name('dns.nameservers.update');
            Route::delete('/dns/nameservers/{id}', [DnsController::class, 'destroyNameserver'])
                ->middleware('role_or_permission:admin|reseller|manage_dns')
                ->name('dns.nameservers.destroy');

            Route::get('/dns/zones', [DnsController::class, 'zones'])
                ->middleware('role_or_permission:admin|reseller|general|general_user|manage_dns')
                ->name('dns.zones');
            Route::post('/dns/zones', [DnsController::class, 'storeZone'])
                ->middleware('role_or_permission:admin|reseller|general|general_user|manage_dns')
                ->name('dns.zones.store');
            Route::patch('/dns/zones/{id}', [DnsController::class, 'updateZone'])
                ->middleware('role_or_permission:admin|reseller|general|general_user|manage_dns')
                ->name('dns.zones.update');
            Route::delete('/dns/zones/{id}', [DnsController::class, 'destroyZone'])
                ->middleware('role_or_permission:admin|reseller|general|general_user|manage_dns')
                ->name('dns.zones.destroy');
            Route::patch('/dns/zones/{id}/transfer', [DnsController::class, 'transferZone'])
                ->middleware('role_or_permission:admin|reseller|manage_dns')
                ->name('dns.zones.transfer');
            Route::get('/dns/cloudflare/review', [DnsController::class, 'reviewCloudflare'])
                ->middleware('role_or_permission:admin|reseller|manage_dns')
                ->name('dns.cloudflare.review');
            Route::post('/dns/cloudflare/sync', [DnsController::class, 'syncCloudflare'])
                ->middleware('role_or_permission:admin|reseller|manage_dns')
                ->name('dns.cloudflare.sync');
            Route::post('/dns/cloudflare/import', [DnsController::class, 'importCloudflareZone'])
                ->middleware('role_or_permission:admin|reseller|manage_dns')
                ->name('dns.cloudflare.import');

            Route::post('/dns/records', [DnsController::class, 'storeRecord'])
                ->middleware('role_or_permission:admin|reseller|general|general_user|manage_dns')
                ->name('dns.records.store');
            Route::patch('/dns/records/{id}', [DnsController::class, 'updateRecord'])
                ->middleware('role_or_permission:admin|reseller|general|general_user|manage_dns')
                ->name('dns.records.update');
            Route::delete('/dns/records/{id}', [DnsController::class, 'destroyRecord'])
                ->middleware('role_or_permission:admin|reseller|general|general_user|manage_dns')
                ->name('dns.records.destroy');

            Route::get('/php/versions', [PhpManagementController::class, 'versions'])
                ->middleware('role:admin|reseller')
                ->name('php.versions');
            Route::get('/php/manager', [PhpManagementController::class, 'manager'])
                ->middleware('role:admin|reseller')
                ->name('php.manager');
            Route::get('/php/extensions', [PhpManagementController::class, 'extensions'])
                ->middleware('role:admin|reseller')
                ->name('php.extensions');
            Route::get('/php/extensions/details', [PhpManagementController::class, 'extensionDetails'])
                ->middleware('role:admin|reseller')
                ->name('php.extensions.details');
            Route::patch('/php/extensions', [PhpManagementController::class, 'updateExtensions'])
                ->middleware('role:admin|reseller')
                ->name('php.extensions.update');
            Route::post('/php/extensions/sync', [PhpManagementController::class, 'syncExtensionsFromServer'])
                ->middleware('role:admin|reseller')
                ->name('php.extensions.sync');

            Route::get('/php/config', [PhpManagementController::class, 'config'])
                ->middleware('role:admin|reseller')
                ->name('php.config');
            Route::get('/php/config/details', [PhpManagementController::class, 'configDetails'])
                ->middleware('role:admin|reseller')
                ->name('php.config.details');
            Route::patch('/php/config', [PhpManagementController::class, 'updateConfig'])
                ->middleware('role:admin|reseller')
                ->name('php.config.update');

            Route::get('/php/settings', [PhpManagementController::class, 'config'])
                ->middleware('role:admin|reseller')
                ->name('php.settings');

            Route::get('/security', [SecurityController::class, 'manager'])
                ->middleware('role:admin|reseller')
                ->name('security.manager');
            Route::get('/security/ports', [SecurityController::class, 'ports'])
                ->middleware('role:admin|reseller')
                ->name('security.ports');
            Route::get('/security/firewall-settings', [SecurityController::class, 'firewall'])
                ->middleware('role:admin|reseller')
                ->name('security.firewall');
            Route::get('/security/ssh-settings', [SecurityController::class, 'ssh'])
                ->middleware('role:admin|reseller')
                ->name('security.ssh');
            Route::get('/security/live', [SecurityController::class, 'live'])
                ->middleware('role:admin|reseller')
                ->name('security.live');
            Route::get('/security/ssh-guide', [SecurityController::class, 'sshGuide'])
                ->middleware('role:admin|reseller')
                ->name('security.ssh.guide');
            Route::get('/security/firewall-guide', [SecurityController::class, 'firewallGuide'])
                ->middleware('role:admin|reseller')
                ->name('security.firewall.guide');

            Route::get('/admin', [UserManagementController::class, 'index'])
                ->middleware('role:admin')
                ->name('admin.panel');

            Route::get('/reseller', [UserManagementController::class, 'index'])
                ->middleware('role:admin|reseller')
                ->name('reseller.panel');

            Route::get('/user-panel', [UserManagementController::class, 'index'])
                ->middleware('role:admin|reseller')
                ->name('user.panel');
            Route::get('/users/manage', [UserManagementController::class, 'index'])
                ->middleware('role:admin|reseller')
                ->name('users.manage');
            Route::post('/users/manage', [UserManagementController::class, 'store'])
                ->middleware('role:admin|reseller')
                ->name('users.manage.store');
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
            Route::patch('/profile/two-factor', [ProfileController::class, 'updateTwoFactor'])->name('profile.two-factor.update');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        });
    });

require __DIR__.'/auth.php';
