<?php

namespace App\Services\Mcp;

/**
 * A tool failed in a way the model should see and can recover from (bad
 * input, refused command). Reported as a tool result with isError=true,
 * not as a JSON-RPC protocol error.
 */
class McpToolException extends \RuntimeException {}
