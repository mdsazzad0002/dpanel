<?php

namespace App\Http\Middleware;

use App\Models\PanelSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EnsurePanelSessionIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');
        $cookieToken = (string) $request->cookie($cookieName, '');
        $missingProofCookie = $cookieToken === '';
        if ($missingProofCookie) {
            $cookieToken = bin2hex(random_bytes(32));
        }

        $routeToken = (string) $request->route('token');
        $sessionToken = $request->hasSession()
            ? (string) $request->session()->get('panel_session_token', '')
            : '';

        Log::info('DEBUG_PANEL_SESSION entry', [
            'scheme' => $request->getScheme(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'path' => $request->path(),
            'missingProofCookie' => $missingProofCookie,
            'routeToken' => $routeToken,
            'sessionToken' => $sessionToken,
            'all_cookie_names' => array_keys($request->cookies->all()),
        ]);

        // A previously-open tab can keep making requests with an older cpsess
        // token after a fresh login rotates it. Show the login screen for that
        // stale URL, but never overwrite/revoke the current valid session.
        if ($routeToken !== '' && $sessionToken !== '' && ! hash_equals($sessionToken, $routeToken)) {
            $currentSessionExists = PanelSession::query()
                ->where('user_id', Auth::id())
                ->where('token_hash', hash('sha256', $sessionToken))
                ->when(! $missingProofCookie, fn ($query) => $query->where('cookie_hash', hash('sha256', $cookieToken)))
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->where('created_at', '>', now()->subMinutes(PanelSession::maximumLifetimeMinutes()))
                ->exists();

            if ($currentSessionExists) {
                return redirect()->route('login');
            }
        }

        $token = $routeToken !== '' ? $routeToken : $sessionToken;

        if ($request->hasSession() && $token !== '') {
            URL::defaults(['token' => $token]);
        }

        $activeSession = $token !== '' && $cookieToken !== ''
            ? PanelSession::query()
                ->where('user_id', Auth::id())
                ->where('token_hash', hash('sha256', $token))
                ->when(! $missingProofCookie, fn ($query) => $query->where('cookie_hash', hash('sha256', $cookieToken)))
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->where('created_at', '>', now()->subMinutes(PanelSession::maximumLifetimeMinutes()))
                ->first()
            : null;

        if (! $activeSession) {
            Log::info('DEBUG_PANEL_SESSION force_logout', [
                'token' => $token,
                'cookieToken_present' => $cookieToken !== '',
                'missingProofCookie' => $missingProofCookie,
            ]);
            Auth::guard('web')->logout();
            $request->session()->forget('panel_session_token');
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withCookie(Cookie::forget($cookieName));
        }

        // Persist only after the route token has been proven active.
        $request->session()->put('panel_session_token', $token);

        if ($missingProofCookie) {
            PanelSession::query()
                ->where('user_id', Auth::id())
                ->where('id', '!=', $activeSession->id)
                ->delete();
        }

        $nextExpiry = $activeSession->refreshedExpiresAt();
        $activeSession->forceFill([
            'cookie_hash' => hash('sha256', $cookieToken),
            'last_seen_at' => now(),
            'expires_at' => $nextExpiry,
        ])->save();

        $response = $next($request);

        $cookieMinutes = max(1, (int) ceil(now()->diffInSeconds($nextExpiry, false) / 60));
        $response->headers->setCookie(cookie(
            name: $cookieName,
            value: $cookieToken,
            minutes: $cookieMinutes,
            path: (string) config('session.path', '/'),
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'Lax'
        ));

        return $response;
    }
}
