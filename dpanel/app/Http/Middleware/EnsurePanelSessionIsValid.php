<?php

namespace App\Http\Middleware;

use App\Models\PanelSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelSessionIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');
        if ($token === '' && $request->hasSession()) {
            $token = (string) $request->session()->get('panel_session_token', '');
        }
        $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');
        $cookieToken = (string) $request->cookie($cookieName, '');

        if ($token === '' || $cookieToken === '') {
            if ($token !== '' && $this->reissuePanelProofCookieIfPossible($request, $token, $cookieName)) {
                return $next($request);
            }

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

        if ($request->hasSession()) {
            $request->session()->put('panel_session_token', $token);
            URL::defaults(['token' => $token]);
        }

        return $next($request);
    }

    private function reissuePanelProofCookieIfPossible(Request $request, string $token, string $cookieName): bool
    {
        if (! Auth::check() || ! $request->hasSession()) {
            return false;
        }

        $sessionToken = (string) $request->session()->get('panel_session_token', '');
        if ($sessionToken === '' || $sessionToken !== $token) {
            return false;
        }

        $cookieToken = bin2hex(random_bytes(32));

        PanelSession::create([
            'user_id' => (int) Auth::id(),
            'token_hash' => hash('sha256', $token),
            'cookie_hash' => hash('sha256', $cookieToken),
            'ip_address' => (string) $request->ip(),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
            'expires_at' => now()->addYear(),
            'last_seen_at' => now(),
        ]);

        $request->cookies->set($cookieName, $cookieToken);
        Cookie::queue(cookie(
            name: $cookieName,
            value: $cookieToken,
            minutes: 60 * 24 * 365,
            path: (string) config('session.path', '/'),
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'Lax'
        ));

        return true;
    }

    private function revokeAndRedirect(Request $request, ?PanelSession $session, string $cookieName): Response
    {
        if ($session !== null && $session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->forget('panel_session_token');
        }

        return redirect()
            ->route('login')
            ->withCookie(Cookie::forget($cookieName));
    }
}
