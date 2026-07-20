<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SsoController extends Controller
{
    public function consumeWebmail(Request $request): JsonResponse
    {
        $secret = trim((string) config('app.webmail_sso_secret', ''));
        if ((bool) config('app.webmail_sso_require_local', true)) {
            $ip = (string) $request->ip();
            if (! in_array($ip, ['127.0.0.1', '::1'], true)) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        if ($secret !== '') {
            $provided = $this->readBearerToken($request) ?: trim((string) $request->header('X-ServerPanel-SSO', ''));
            if ($provided === '' || ! hash_equals($secret, $provided)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }
        }

        $token = trim((string) $request->input('token', ''));
        if ($token === '' || strlen($token) > 256 || ! ctype_xdigit($token)) {
            return response()->json(['success' => false, 'message' => 'Invalid token'], 400);
        }

        $key = 'serverpanel:webmail_sso:'.$token;
        $payload = Cache::pull($key);
        if (! is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $email = (string) ($payload['email'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        if ($email === '' || $password === '') {
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 400);
        }

        return response()
            ->json(['success' => true, 'email' => $email, 'password' => $password])
            ->header('Cache-Control', 'no-store');
    }

    private function readBearerToken(Request $request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if ($authorization === '') {
            return '';
        }

        if (preg_match('/^Bearer\\s+(.*)$/i', $authorization, $m)) {
            return trim((string) ($m[1] ?? ''));
        }

        return '';
    }
}
