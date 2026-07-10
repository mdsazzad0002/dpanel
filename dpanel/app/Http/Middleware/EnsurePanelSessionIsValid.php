<?php

namespace App\Http\Middleware;

use App\Models\PanelSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
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
            return $this->revokeAndRedirect($request, null, $cookieName);
        }

        $session = PanelSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('cookie_hash', hash('sha256', $cookieToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            return $this->revokeAndRedirect($request, null, $cookieName);
        }

        if ($session->ip_address !== (string) $request->ip()) {
            return $this->revokeAndRedirect($request, $session, $cookieName);
        }

        $currentUserAgentHash = hash('sha256', (string) $request->userAgent());

        if ($session->user_agent_hash !== $currentUserAgentHash) {
            return $this->revokeAndRedirect($request, $session, $cookieName);
        }

        $timeoutMinutes = max(1, (int) config('serverpanel.panel_inactivity_timeout', config('session.lifetime', 120)));

        if ($session->last_seen_at && $session->last_seen_at->lt(now()->subMinutes($timeoutMinutes))) {
            return $this->revokeAndRedirect($request, $session, $cookieName);
        }

        $session->forceFill(['last_seen_at' => now()])->save();

        if (! Auth::check() || Auth::id() !== $session->user_id) {
            Auth::loginUsingId($session->user_id);
        }

        $request->session()->put('panel_session_token', $token);
        URL::defaults(['token' => $token]);

        return $next($request);
    }

    private function revokeAndRedirect(Request $request, ?PanelSession $session, string $cookieName): Response
    {
        if ($session !== null && $session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->forget('panel_session_token');

        return redirect()
            ->route('login')
            ->withCookie(Cookie::forget($cookieName));
    }
}
