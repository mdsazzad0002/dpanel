<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\PanelSession;
use App\Services\TwoFactorService;
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
    public function store(LoginRequest $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $request->authenticate();
        $request->session()->regenerate();

        if ((bool) $user->two_factor_enabled && $twoFactor->availableMethods($user) === []) {
            return back()->withErrors([
                'email' => 'Two-factor is enabled for your account, but no verification method is configured.',
            ]);
        }

        if ($twoFactor->requiresChallenge($user)) {
            $method = (string) ($twoFactor->preferredMethod($user) ?? 'email');
            $code = $twoFactor->normalizeCode($twoFactor->generateNumericCode());

            $request->session()->put('two_factor.challenge', [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'method' => $method,
                'code_hash' => hash('sha256', $code),
                'expires_at' => now()->addMinutes((int) ($twoFactor->policy()['code_ttl_minutes'] ?? 10))->toIso8601String(),
                'attempts' => 0,
            ]);

            try {
                if (in_array($method, ['email', 'telegram'], true)) {
                    $twoFactor->sendChallenge($user, $method, $code);
                }
            } catch (\Throwable $e) {
                $request->session()->forget('two_factor.challenge');

                return back()->withErrors([
                    'email' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to send two-factor code.',
                ]);
            }

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $panelCookie = $this->issuePanelSessionProof($request);

        return redirect()->intended(route('dashboard', absolute: false))
            ->withCookie($panelCookie);
    }

    private function issuePanelSessionProof(Request $request, ?string $token = null)
    {
        $token ??= (string) $request->session()->get('panel_session_token', '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $request->session()->put('panel_session_token', $token);
        }

        URL::defaults(['token' => $token]);

        $cookieToken = bin2hex(random_bytes(32));
        $lifetime = max(1, (int) config('serverpanel.panel_token_lifetime', config('session.lifetime', 120)));
        $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');

        PanelSession::syncSingleSession(
            userId: (int) $request->user()->id,
            token: $token,
            cookieToken: $cookieToken,
            ipAddress: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
            expiresAt: now()->addMinutes($lifetime),
            lastSeenAt: now(),
        );

        $panelCookie = cookie(
            name: $cookieName,
            value: $cookieToken,
            minutes: $lifetime,
            path: (string) config('session.path', '/'),
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'Lax'
        );

        $request->cookies->set($cookieName, $cookieToken);
        Cookie::queue($panelCookie);

        return $panelCookie;
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
                ->delete();
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        $request->session()->forget('panel_session_token');

        return redirect('/')
            ->withCookie(Cookie::forget($cookieName));
    }
}
