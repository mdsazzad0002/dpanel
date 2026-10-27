<?php

return [
    // Turn the remote MCP endpoint (/api/mcp) off entirely.
    'enabled' => (bool) env('DPANEL_MCP_ENABLED', true),
    // Command output returned to the MCP client is cut to the last N characters.
    'max_output_chars' => (int) env('DPANEL_MCP_MAX_OUTPUT_CHARS', 30000),
    // Requests per minute per token/IP.
    'rate_limit' => (int) env('DPANEL_MCP_RATE_LIMIT', 120),
    // Log files read_log_file may read must live under one of these.
    'log_roots' => ['/var/log/'],
    // Written by `sudo dpanel mcp local on [read|full]`; enables commands on the panel host.
    'local_config_path' => env('DPANEL_MCP_LOCAL_CONFIG', '/etc/dpanel/mcp-local.conf'),
    'oauth' => [
        // Let MCP clients sign in with OAuth (needed for claude.ai connectors).
        'enabled' => (bool) env('DPANEL_MCP_OAUTH_ENABLED', true),
        'access_token_ttl' => (int) env('DPANEL_MCP_OAUTH_ACCESS_TTL', 3600),
        'refresh_token_ttl_days' => (int) env('DPANEL_MCP_OAUTH_REFRESH_TTL_DAYS', 30),
    ],
];
