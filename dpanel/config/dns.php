<?php

return [
    'engine' => env('DNS_ENGINE', 'powerdns'),
    'authoritative_mode' => env('DNS_AUTHORITATIVE_MODE', 'database'),
    'default_ttl' => (int) env('DNS_DEFAULT_TTL', 3600),
    'allow_dynamic_updates' => (bool) env('DNS_ALLOW_DYNAMIC_UPDATES', true),
    'our_nameservers' => array_values(array_filter(array_map('trim', explode(',', (string) env('DNS_OUR_NAMESERVERS', 'ns1.dengrweb.com,ns2.dengrweb.com'))))),
];
