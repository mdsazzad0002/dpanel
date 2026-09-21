<?php

// Scoped only to the public website chat widget — every other route is
// same-origin (the panel UI) or a signed server-to-server webhook, so no
// other path needs cross-origin access.
return [
    'paths' => ['widget/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
