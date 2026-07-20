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

        $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');
        $cookieToken = (string) $request->cookie($cookieName, '');
        $issueCookie = false;
        if ($cookieToken === '') {
            $cookieToken = bin2hex(random_bytes(32));
            $issueCookie = true;
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
            PanelSession::syncSingleSession(
                userId: (int) Auth::id(),
                token: $token,
                cookieToken: $cookieToken,
                ipAddress: (string) $request->ip(),
                userAgent: (string) $request->userAgent(),
                expiresAt: now()->addYear(),
                lastSeenAt: now(),
            );
        }

        $response = $next($request);

        if ($issueCookie) {
            $response->headers->setCookie(cookie(
                name: $cookieName,
                value: $cookieToken,
                minutes: max(1, (int) config('serverpanel.panel_token_lifetime', config('session.lifetime', 120))),
                path: (string) config('session.path', '/'),
                domain: config('session.domain'),
                secure: (bool) config('session.secure'),
                httpOnly: true,
                raw: false,
                sameSite: 'Lax'
            ));
        }

        return $response;
    }
}
