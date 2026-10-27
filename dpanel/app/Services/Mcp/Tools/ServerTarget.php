<?php

namespace App\Services\Mcp\Tools;

/**
 * Shared JSON Schema for the server_id argument of command-running tools.
 */
final class ServerTarget
{
    public const SCHEMA = [
        'type' => ['integer', 'string'],
        'description' => 'A server id from list_servers, or "local" for the panel host. Optional: defaults to the panel host when MCP commands are enabled there, otherwise to the only server.',
    ];
}
