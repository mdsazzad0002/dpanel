<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\ActivityLogService;
use App\Support\ApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Each user's own tokens for the public API (backlog U1). Anyone may make
 * them, but only with the scopes their role allows; a token never does more
 * than its owner.
 */
class ApiTokenController extends Controller
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/ApiTokens/Index', [
            'tokens' => ApiToken::query()->where('user_id', $user->id)->orderByDesc('created_at')->get()
                ->map(fn (ApiToken $token) => $this->serialise($token))->values(),
            'scopes' => ApiScopes::allowedFor($user),
            'baseUrl' => rtrim($request->getSchemeAndHttpHost(), '/').'/api/v1',
            'docsUrl' => route('api.v1.openapi'),
            'maxDays' => (int) config('api.max_token_days', 3650),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in(ApiScopes::allowedFor($user))],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('api.max_token_days', 3650)],
        ]);

        $generated = ApiToken::generate();
        $token = ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'token_prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'scopes' => array_values(array_intersect(ApiScopes::all(), $data['scopes'])),
            'expires_at' => isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
        ]);

        $this->activityLog->log('api.token_created', $token, ['name' => $token->name, 'scopes' => $token->scopes]);

        return response()->json([
            'token' => $this->serialise($token),
            'plain_token' => $generated['plain'],
        ], 201);
    }

    public function destroy(Request $request, string $token, string $apiToken): JsonResponse
    {
        $found = ApiToken::query()->where('user_id', $request->user()->id)->findOrFail($apiToken);
        $this->activityLog->log('api.token_revoked', $found, ['name' => $found->name]);
        $found->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function serialise(ApiToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'token_prefix' => $token->token_prefix,
            'scopes' => array_values((array) $token->scopes),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'last_used_ip' => $token->last_used_ip,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'expired' => $token->isExpired(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }
}
