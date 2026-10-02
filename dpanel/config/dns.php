<?php

return [
    'engine' => env('DNS_ENGINE', 'powerdns'),
    'authoritative_mode' => env('DNS_AUTHORITATIVE_MODE', 'database'),
    'default_ttl' => (int) env('DNS_DEFAULT_TTL', 3600),
    'allow_dynamic_updates' => (bool) env('DNS_ALLOW_DYNAMIC_UPDATES', true),
    // This server's nameserver host names, e.g. "ns1.example.com,ns2.example.com".
    // New zones publish them as NS records. No default: they must be names
    // that really point at this server.
    'our_nameservers' => array_values(array_filter(array_map('trim', explode(',', (string) env('DNS_OUR_NAMESERVERS', ''))))),
];
