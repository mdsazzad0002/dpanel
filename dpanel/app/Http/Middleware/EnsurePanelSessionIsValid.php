<?php

namespace App\Http\Middleware;

use App\Models\PanelSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelSessionIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $token = (string) $request->route('token');
        if ($token === '' && $request->hasSession()) {
            $token = (string) $request->session()->get('panel_session_token', '');
        }

        if ($request->hasSession()) {
            if ($token === '') {
                $token = bin2hex(random_bytes(32));
                $request->session()->put('panel_session_token', $token);
            }

            $request->session()->put('panel_session_token', $token);
            URL::defaults(['token' => $token]);
        }

        if ($token !== '' && Auth::check()) {
            PanelSession::query()->updateOrCreate(
                ['token_hash' => hash('sha256', $token)],
                [
                    'user_id' => (int) Auth::id(),
                    'cookie_hash' => hash('sha256', $request->cookie((string) config('serverpanel.panel_cookie_name', 'panel_session_proof'), '')),
                    'ip_address' => (string) $request->ip(),
                    'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
                    'expires_at' => now()->addYear(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                ]
            );
        }

        return $next($request);
    }
}
