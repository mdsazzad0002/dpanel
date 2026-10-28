<?php

namespace App\Services\Mcp;

/**
 * A JSON-RPC level error (unknown method, bad params). The exception code
 * is the JSON-RPC error code.
 */
class McpProtocolException extends \RuntimeException {}
