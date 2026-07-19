<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PanelSession;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request, TwoFactorService $twoFactor): Response|RedirectResponse
    {
        $pending = $request->session()->get('two_factor.challenge');
        if (! is_array($pending) || ! isset($pending['user_id'])) {
            return redirect()->route('login');
        }

        $user = User::query()->find((int) $pending['user_id']);
        if (! $user instanceof User) {
            $request->session()->forget('two_factor.challenge');

            return redirect()->route('login');
        }

        $availableMethods = $twoFactor->availableMethods($user);
        $method = (string) ($pending['method'] ?? $twoFactor->preferredMethod($user) ?? '');
        if ($method === '' || ! in_array($method, $availableMethods, true)) {
            $method = (string) ($twoFactor->preferredMethod($user) ?? $availableMethods[0] ?? '');
        }

        if ($method === '' || ! in_array($method, $availableMethods, true)) {
            $request->session()->forget('two_factor.challenge');

            return redirect()->route('login')->withErrors([
                'email' => 'Two-factor is not configured for this account.',
            ]);
        }

        $request->session()->put('two_factor.challenge.method', $method);

        try {
            $this->sendChallengeIfNeeded($request, $user, $twoFactor, $method);
        } catch (\Throwable $e) {
            $request->session()->forget('two_factor.challenge');

            return redirect()->route('login')->withErrors([
                'email' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to send two-factor code.',
            ]);
        }

        return Inertia::render('Auth/TwoFactorChallenge', [
            'method' => $method,
            'availableMethods' => $availableMethods,
            'methodLabel' => $twoFactor->methodLabel($method),
            'maskedDestination' => $this->maskedDestination($user, $method),
            'expiresIn' => (int) ($twoFactor->policy()['code_ttl_minutes'] ?? 10),
        ]);
    }

    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $pending = $request->session()->get('two_factor.challenge');
        if (! is_array($pending) || ! isset($pending['user_id'])) {
            return redirect()->route('login');
        }

        $user = User::query()->find((int) $pending['user_id']);
        if (! $user instanceof User) {
            $request->session()->forget('two_factor.challenge');

            return redirect()->route('login');
        }

        $availableMethods = $twoFactor->availableMethods($user);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $method = (string) ($pending['method'] ?? $twoFactor->preferredMethod($user) ?? '');
        if ($method === '' || ! in_array($method, $availableMethods, true)) {
            return redirect()->route('two-factor.challenge');
        }

        $expiresAt = isset($pending['expires_at']) ? strtotime((string) $pending['expires_at']) : false;
        if ($expiresAt !== false && $expiresAt < time()) {
            $request->session()->forget('two_factor.challenge');

            return back()->withErrors([
                'code' => 'The two-factor code has expired. Please sign in again.',
            ]);
        }

        $code = $twoFactor->normalizeCode((string) $validated['code']);
        $valid = false;

        if ($method === 'google_auth_app') {
            $valid = $twoFactor->verifyTotp($user, $code);
        } else {
            $storedHash = (string) ($pending['code_hash'] ?? '');
            $valid = $storedHash !== '' && hash_equals($storedHash, hash('sha256', $code));
        }

        if (! $valid) {
            $attempts = (int) ($pending['attempts'] ?? 0) + 1;
            $request->session()->put('two_factor.challenge.attempts', $attempts);

            return back()->withErrors([
                'code' => 'The two-factor code is invalid.',
            ]);
        }

        $request->session()->forget('two_factor.challenge');
        $request->session()->regenerate();

        Auth::loginUsingId($user->id, (bool) ($pending['remember'] ?? false));
        $panelCookie = $this->issuePanelSessionProof($request);

        return redirect()->intended(route('dashboard', absolute: false))
            ->withCookie($panelCookie);
    }

    public function selectMethod(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $pending = $request->session()->get('two_factor.challenge');
        if (! is_array($pending) || ! isset($pending['user_id'])) {
            return redirect()->route('login');
        }

        $user = User::query()->find((int) $pending['user_id']);
        if (! $user instanceof User) {
            $request->session()->forget('two_factor.challenge');

            return redirect()->route('login');
        }

        $validated = $request->validate([
            'method' => ['required', 'in:email,telegram,google_auth_app'],
        ]);

        $method = (string) $validated['method'];
        if (! in_array($method, $twoFactor->availableMethods($user), true)) {
            return back()->withErrors([
                'method' => 'This verification method is not available.',
            ]);
        }

        $request->session()->put('two_factor.challenge.method', $method);
        $request->session()->forget('two_factor.challenge.code_hash');
        $request->session()->forget('two_factor.challenge.expires_at');
        $request->session()->put('two_factor.challenge.attempts', 0);

        try {
            $this->sendChallengeIfNeeded($request, $user, $twoFactor, $method);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'method' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to change verification method.',
            ]);
        }

        return redirect()->route('two-factor.challenge');
    }

    public function resend(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $pending = $request->session()->get('two_factor.challenge');
        if (! is_array($pending) || ! isset($pending['user_id'])) {
            return redirect()->route('login');
        }

        $user = User::query()->find((int) $pending['user_id']);
        if (! $user instanceof User) {
            $request->session()->forget('two_factor.challenge');

            return redirect()->route('login');
        }

        $method = (string) ($pending['method'] ?? '');
        if (! in_array($method, ['email', 'telegram'], true)) {
            return redirect()->route('two-factor.challenge');
        }

        try {
            $this->sendChallengeIfNeeded($request, $user, $twoFactor, $method, true);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'code' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to resend two-factor code.',
            ]);
        }

        return back()->with('status', 'A fresh two-factor code has been sent.');
    }

    private function sendChallengeIfNeeded(Request $request, User $user, TwoFactorService $twoFactor, string $method, bool $force = false): void
    {
        if (! in_array($method, ['email', 'telegram'], true)) {
            return;
        }

        $pending = $request->session()->get('two_factor.challenge');
        $hasCode = is_array($pending) && trim((string) ($pending['code_hash'] ?? '')) !== '';

        if (! $force && $hasCode) {
            $request->session()->put('two_factor.challenge.method', $method);
            return;
        }

        $code = $twoFactor->generateNumericCode();
        $request->session()->put('two_factor.challenge.code_hash', hash('sha256', $code));
        $request->session()->put('two_factor.challenge.expires_at', now()->addMinutes((int) ($twoFactor->policy()['code_ttl_minutes'] ?? 10))->toIso8601String());

        try {
            $twoFactor->sendChallenge($user, $method, $code);
        } catch (\Throwable $e) {
            $request->session()->forget('two_factor.challenge.code_hash');
            $request->session()->forget('two_factor.challenge.expires_at');
            throw $e;
        }
    }

    private function maskedDestination(User $user, string $method): ?string
    {
        return match ($method) {
            'email' => $this->maskEmail($user->email),
            'telegram' => $this->maskTelegram($user->two_factor_telegram_chat_id ?: ''),
            default => null,
        };
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = max(1, min(3, strlen($local)));

        return substr($local, 0, $visible).str_repeat('*', max(0, strlen($local) - $visible)).'@'.$domain;
    }

    private function maskTelegram(string $chatId): string
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return '';
        }

        return strlen($chatId) <= 4 ? str_repeat('*', strlen($chatId)) : substr($chatId, 0, 2).str_repeat('*', strlen($chatId) - 4).substr($chatId, -2);
    }

    private function issuePanelSessionProof(Request $request)
    {
        $token = (string) $request->session()->get('panel_session_token', '');
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
}
