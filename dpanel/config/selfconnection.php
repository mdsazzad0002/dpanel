<?php

return [

    'env_backup_dir' => storage_path('app/env-backups'),

    'env_backup_retention' => (int) env('SELFCONNECTION_ENV_BACKUP_RETENTION', 20),

    'php_fpm_service' => env('SELFCONNECTION_PHP_FPM_SERVICE', 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'-fpm'),

    'queue_supervisor_programs' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'SELFCONNECTION_QUEUE_PROGRAMS',
        'dpanel-queue,dpanel-heavy-queue,dpanel-chat-queue'
    ))))),

    'restart_settle_ms' => (int) env('SELFCONNECTION_RESTART_SETTLE_MS', 500),

];
