<?php

return [

    // Written to .env by the dscript mongodb module at install time.
    'host' => env('MONGODB_HOST', '127.0.0.1'),

    'port' => (int) env('MONGODB_PORT', 27017),

    'admin_username' => env('MONGODB_ADMIN_USERNAME', 'admin'),

    'admin_password' => env('MONGODB_ADMIN_PASSWORD', ''),

    'services' => [
        'mongodb' => [
            'label' => 'MongoDB',
            'unit' => env('MONGODB_SERVICE', 'mongod'),
        ],
    ],

];
