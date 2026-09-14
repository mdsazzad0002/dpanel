<?php

return [

    'port' => (int) env('REMOTE_MYSQL_PORT', 3306),

    'bind_config_path' => env('REMOTE_MYSQL_BIND_CONFIG_PATH', '/etc/mysql/mariadb.conf.d/50-server.cnf'),

    'service_name' => env('REMOTE_MYSQL_SERVICE', 'mariadb'),

    'external_bind_address' => env('REMOTE_MYSQL_EXTERNAL_BIND', '0.0.0.0'),

    'loopback_bind_address' => '127.0.0.1',

    'backup_dir' => storage_path('app/mysql-config-backups'),

];
