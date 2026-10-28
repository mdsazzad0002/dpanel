<?php

namespace App\Services\Mcp\Contracts;

use App\Models\McpAccessToken;

interface McpTool
{
    /** Unique tool name exposed to MCP clients (snake_case). */
    public function name(): string;

    public function title(): string;

    /** What the tool does and when a model should call it. */
    public function description(): string;

    /** JSON Schema for the tool's arguments. */
    public function inputSchema(): array;

    /** False when the tool can change server state (needs a full-access token). */
    public function readOnly(): bool;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> Returned to the client as JSON.
     *
     * @throws \App\Services\Mcp\McpToolException
     */
    public function handle(array $arguments, McpAccessToken $token): array;
}
