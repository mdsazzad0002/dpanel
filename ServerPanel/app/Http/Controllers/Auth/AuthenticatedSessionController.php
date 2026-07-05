<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\PanelSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $urlToken = bin2hex(random_bytes(32));
        $cookieToken = bin2hex(random_bytes(32));
        $lifetime = max(1, (int) config('serverpanel.panel_token_lifetime', config('session.lifetime', 120)));
        $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');

        PanelSession::create([
            'user_id' => $request->user()->id,
            'token_hash' => hash('sha256', $urlToken),
            'cookie_hash' => hash('sha256', $cookieToken),
            'ip_address' => (string) $request->ip(),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
            'expires_at' => now()->addMinutes($lifetime),
            'last_seen_at' => now(),
        ]);

        $request->session()->put('panel_session_token', $urlToken);
        URL::defaults(['token' => $urlToken]);

        return redirect()->intended(route('dashboard', absolute: false))
            ->withCookie(cookie(
                name: $cookieName,
                value: $cookieToken,
                minutes: $lifetime,
                path: (string) config('session.path', '/'),
                domain: config('session.domain'),
                secure: (bool) config('session.secure'),
                httpOnly: true,
                raw: false,
                sameSite: 'Lax'
            ));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');
        $token = (string) $request->session()->get('panel_session_token', '');

        if ($token !== '') {
            PanelSession::query()
                ->where('user_id', $request->user()?->id)
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        $request->session()->forget('panel_session_token');

        return redirect('/')
            ->withCookie(Cookie::forget($cookieName));
    }
}
