<?php

namespace App\Http\Middleware;

use App\Models\AiGatewayApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates external AI Gateway API requests via an
 * `Authorization: Bearer sk-ag-...` header, OpenAI/OpenRouter-style.
 */
class AuthenticateAiGatewayApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token || ! str_starts_with($token, 'sk-ag-')) {
            return $this->unauthorized('Missing or malformed Authorization header. Expected: Authorization: Bearer sk-ag-...');
        }

        $key = AiGatewayApiKey::findActiveByPlainKey($token);

        if (! $key) {
            return $this->unauthorized('Invalid or revoked API key.');
        }

        $key->touchLastUsed();
        $request->attributes->set('ai_gateway_api_key', $key);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], 401);
    }
}
