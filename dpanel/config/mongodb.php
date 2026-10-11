<?php

return [

    // Written to .env by the dscript mongodb module at install time.
    'host' => env('MONGODB_HOST', '127.0.0.1'),

    'port' => (int) env('MONGODB_PORT', 27017),

    'admin_username' => env('MONGODB_ADMIN_USERNAME', 'admin'),

    'admin_password' => env('MONGODB_ADMIN_PASSWORD', ''),

    // Fixed system path proxied by the drust edge gateway on the panel domain.
    'mongo_express_path' => '/mongo-express/',

    'services' => [
        'mongodb' => [
            'label' => 'MongoDB',
            'unit' => env('MONGODB_SERVICE', 'mongod'),
        ],
        'mongo-express' => [
            'label' => 'mongo-express',
            'unit' => env('MONGO_EXPRESS_SERVICE', 'dpanel-mongo-express'),
        ],
    ],

];
