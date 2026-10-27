<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Remote MCP endpoint (Streamable HTTP transport). Clients POST JSON-RPC
 * messages and get a JSON reply; there is no server-initiated SSE stream,
 * so GET answers 405 as the spec allows.
 */
class McpController extends Controller
{
    public function handle(Request $request, McpServer $server): JsonResponse|Response
    {
        if (! config('mcp.enabled', true)) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'The MCP endpoint is disabled on this panel.']], 503);
        }

        if (! $request->isMethod('POST')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        $payload = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }

        $token = $request->attributes->get('mcp_token');

        // Older protocol versions allow batching several messages in one array.
        if (is_array($payload) && array_is_list($payload) && $payload !== []) {
            $responses = array_values(array_filter(array_map(fn ($message) => $server->handleMessage($message, $token), $payload)));

            return $responses === [] ? response('', 202) : response()->json($responses);
        }

        $response = $server->handleMessage($payload, $token);

        return $response === null ? response('', 202) : response()->json($response);
    }
}
