<?php

return [

    // Written to .env by the dscript postgresql module at install time.
    'host' => env('PGSQL_HOST', '127.0.0.1'),

    'port' => (int) env('PGSQL_PORT', 5432),

    'admin_username' => env('PGSQL_ADMIN_USERNAME', 'postgres'),

    'admin_password' => env('PGSQL_ADMIN_PASSWORD', ''),

    'pgadmin_email' => env('PGADMIN_EMAIL', ''),

    'pgadmin_password' => env('PGADMIN_PASSWORD', ''),

    // Fixed system path proxied by the drust edge gateway on the panel domain.
    'pgadmin_path' => env('PGADMIN_PATH', '/pgadmin4/'),

    'services' => [
        'postgresql' => [
            'label' => 'PostgreSQL',
            'unit' => env('PGSQL_SERVICE', 'postgresql'),
        ],
        'pgadmin' => [
            'label' => 'pgAdmin',
            'unit' => env('PGADMIN_SERVICE', 'dpanel-pgadmin'),
        ],
    ],

];
