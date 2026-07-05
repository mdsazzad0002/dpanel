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
        $token = (string) ($request->route('token') ?: $request->session()->get('panel_session_token', ''));
        $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');
        $cookieToken = (string) $request->cookie($cookieName, '');

        if ($token === '' || $cookieToken === '') {
            abort(403);
        }

        $session = PanelSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('cookie_hash', hash('sha256', $cookieToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            abort(403);
        }

        if ($session->ip_address !== (string) $request->ip()) {
            $session->forceFill(['revoked_at' => now()])->save();
            abort(403);
        }

        $currentUserAgentHash = hash('sha256', (string) $request->userAgent());

        if ($session->user_agent_hash !== $currentUserAgentHash) {
            $session->forceFill(['revoked_at' => now()])->save();
            abort(403);
        }

        $timeoutMinutes = max(1, (int) config('serverpanel.panel_inactivity_timeout', config('session.lifetime', 120)));

        if ($session->last_seen_at && $session->last_seen_at->lt(now()->subMinutes($timeoutMinutes))) {
            $session->forceFill(['revoked_at' => now()])->save();
            abort(403);
        }

        $session->forceFill(['last_seen_at' => now()])->save();

        if (! Auth::check() || Auth::id() !== $session->user_id) {
            Auth::loginUsingId($session->user_id);
        }

        $request->session()->put('panel_session_token', $token);
        URL::defaults(['token' => $token]);

        return $next($request);
    }
}
