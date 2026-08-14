<?php

return [
    'client_id' => env('WHMCS_API_CLIENT_ID', ''),
    'secret' => env('WHMCS_API_SECRET', ''),
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', env('WHMCS_ALLOWED_IPS', ''))))),
    'allowed_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => strtolower(trim($domain)),
        explode(',', env('WHMCS_ALLOWED_DOMAINS', ''))
    ))),
    'timestamp_tolerance' => (int) env('WHMCS_TIMESTAMP_TOLERANCE', 300),
    'sso_ttl' => (int) env('WHMCS_SSO_TTL', 60),
];
