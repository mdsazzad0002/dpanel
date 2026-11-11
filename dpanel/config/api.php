<?php

// Public REST API at /api/v1 (backlog U1). See docs/api.md.
return [
    // Requests a minute for each token, across all endpoints.
    'rate_limit' => (int) env('DPANEL_API_RATE_LIMIT', 120),

    // Longest lifetime a new token may be given, in days.
    'max_token_days' => (int) env('DPANEL_API_MAX_TOKEN_DAYS', 3650),
];
