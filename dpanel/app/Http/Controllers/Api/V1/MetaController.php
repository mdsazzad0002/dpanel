<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Support\ApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Who the token is, and the OpenAPI document of the API. */
class MetaController extends ApiController
{
    public const OPENAPI_FILE = 'resources/api/openapi.yaml';

    public function me(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');
        $user = $request->user();

        return response()->json(['data' => [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'token' => [
                'name' => $token->name,
                'scopes' => array_values((array) $token->scopes),
                // Scopes the owner may no longer use are listed but refused.
                'usable_scopes' => array_values(array_filter((array) $token->scopes, fn (string $scope) => ApiScopes::userMay($user, $scope))),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
        ]]);
    }

    public function openapi(): Response
    {
        return response((string) file_get_contents(base_path(self::OPENAPI_FILE)), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
