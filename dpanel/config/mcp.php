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
];
