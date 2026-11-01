<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Linux accounts that can log in to the server (root and uid >= 1000), read
 * and changed through drust: login shell, password lock, password, sessions.
 * Root-level account control, so only the first panel user (id 1) may use it.
 */
class LoginUserController extends Controller
{
    public function __construct(private readonly ActivityLogService $activity) {}

    public function index(Request $request): Response
    {
        $this->authorizeOwner($request);

        return Inertia::render('Security/LoginUsers/Index');
    }

    public function list(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        try {
            return response()->json(['ok' => true, ...$this->drust()]);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    public function control(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $validated = $request->validate([
            'action' => ['required', 'in:set_shell,lock,unlock,set_password,end_sessions'],
            'user' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_.-]*\$?$/i'],
            'shell' => ['required_if:action,set_shell', 'nullable', 'string', 'max:128', 'regex:#^/[A-Za-z0-9/_.-]+$#'],
            'password' => ['required_if:action,set_password', 'nullable', 'string', 'min:6', 'max:128'],
        ]);

        $payload = array_filter([
            'action' => $validated['action'],
            'user' => $validated['user'],
            'shell' => $validated['shell'] ?? null,
            'password' => $validated['password'] ?? null,
        ], fn ($value) => $value !== null);

        try {
            $result = $this->drust($payload);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $this->activity->log('login_user.'.$validated['action'], null, array_filter([
            'user' => $validated['user'],
            'shell' => $validated['shell'] ?? null,
        ]), $request);

        return response()->json(['ok' => true, 'message' => $result['message']]);
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless((int) $request->user()?->id === 1, 403, 'Only the primary panel user can manage server login users.');
    }

    /**
     * @param  array<string, string>|null  $payload
     * @return array<string, mixed>
     */
    private function drust(?array $payload = null): array
    {
        $baseUrl = trim((string) config('serverpanel.execution_api_base_url', ''));
        if ($baseUrl === '') {
            throw new \RuntimeException('drust API is not configured.');
        }

        $request = Http::acceptJson()->asJson()->timeout(30);
        $token = trim((string) config('serverpanel.execution_api_token', ''));
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        try {
            $url = rtrim($baseUrl, '/').'/api/v1/login-users';
            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('drust API request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if (! $response->successful() || ! (bool) ($json['success'] ?? false)) {
            throw new \RuntimeException((string) ($json['message'] ?? 'drust API failed.'));
        }

        return $payload === null ? (array) ($json['data'] ?? []) : ['message' => (string) ($json['message'] ?? 'Done.')];
    }
}
