<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use App\Services\TwoFactorService;
use App\Support\SecuritySettings;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function __construct(private readonly TwoFactorService $twoFactor, private readonly SecuritySettings $securitySettings)
    {
    }

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'twoFactor' => [
                'enabled' => (bool) $user->two_factor_enabled,
                'method' => trim((string) ($user->two_factor_method ?? '')) ?: 'email',
                'secret' => (string) ($user->two_factor_secret ?? ''),
                'available_methods' => $this->twoFactor->availableMethods($user),
                'global_policy' => $this->securitySettings->read()['two_factor'],
                'provisioning_uri' => $this->twoFactor->hasTotpSecret($user)
                    ? $this->twoFactor->buildProvisioningUri($user, (string) $user->two_factor_secret)
                    : '',
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->fill([
            'name' => $validated['name'],
        ]);

        $user->save();

        return Redirect::route('profile.edit');
    }

    public function updateTwoFactor(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $wasEnabled = (bool) $user->two_factor_enabled;
        $wasMethod = (string) ($user->two_factor_method ?? '');
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'method' => ['required', 'in:email,google_auth_app'],
            'confirmation_code' => ['nullable', 'string', 'max:32'],
        ]);

        $enabled = (bool) $validated['enabled'];
        $method = (string) $validated['method'];
        $confirmationCode = trim((string) ($validated['confirmation_code'] ?? ''));

        if ($confirmationCode === '') {
            if ($method === 'google_auth_app' && $enabled && trim((string) $user->two_factor_secret) === '') {
                $user->two_factor_secret = $this->twoFactor->generateSecret();
                $user->save();

                return Redirect::route('profile.edit')->with('status', 'google-code-required');
            }

            if ($method === 'email') {
                $code = $this->twoFactor->normalizeCode($this->twoFactor->generateNumericCode());
                $request->session()->put('profile.security.challenge', [
                    'method' => $method,
                    'code_hash' => hash('sha256', $code),
                    'expires_at' => now()->addMinutes((int) ($this->securitySettings->read()['two_factor']['code_ttl_minutes'] ?? 10))->toIso8601String(),
                ]);

                try {
                    $this->twoFactor->sendSecurityCode($user, 'email', $code, 'security change');
                } catch (\Throwable $e) {
                    $request->session()->forget('profile.security.challenge');

                    return back()->withErrors([
                        'confirmation_code' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to send security code.',
                    ]);
                }

                if ($request->expectsJson()) {
                    return response()->json([
                        'status' => 'email-code-sent',
                    ]);
                }

                return Redirect::route('profile.edit')->with('status', 'email-code-sent');
            }

            return back()->withErrors([
                'confirmation_code' => 'Enter the code to continue.',
            ]);
        }

        if ($method === 'google_auth_app') {
            if (trim((string) $user->two_factor_secret) === '') {
                return back()->withErrors([
                    'confirmation_code' => 'Enable Google Authenticator first, then scan the QR code and enter the app code.',
                ]);
            }

            if (! $this->twoFactor->verifyTotp($user, $confirmationCode)) {
                return back()->withErrors([
                    'confirmation_code' => 'The authenticator code is invalid.',
                ]);
            }
        } else {
            $challenge = $request->session()->get('profile.security.challenge');
            if (! is_array($challenge)) {
                return back()->withErrors([
                    'confirmation_code' => 'Request a new confirmation code first.',
                ]);
            }

            $challengeMethod = (string) ($challenge['method'] ?? '');
            $expiresAt = isset($challenge['expires_at']) ? strtotime((string) $challenge['expires_at']) : false;
            if ($challengeMethod !== $method || ($expiresAt !== false && $expiresAt < time())) {
                $request->session()->forget('profile.security.challenge');

                return back()->withErrors([
                    'confirmation_code' => 'The confirmation code expired. Request a new one.',
                ]);
            }

            $storedHash = (string) ($challenge['code_hash'] ?? '');
            if ($storedHash === '' || ! hash_equals($storedHash, hash('sha256', $confirmationCode))) {
                return back()->withErrors([
                    'confirmation_code' => 'The confirmation code is invalid.',
                ]);
            }

            $request->session()->forget('profile.security.challenge');
        }

        if ($method === 'google_auth_app' && trim((string) $user->two_factor_secret) === '') {
            $user->two_factor_secret = $this->twoFactor->generateSecret();
        }

        if (! $enabled && $method === 'google_auth_app') {
            $user->two_factor_secret = '';
        }

        if ($method === 'email') {
            $user->email_verified_at = now();
        }

        $user->two_factor_enabled = $enabled;
        $user->two_factor_method = $method;
        $user->two_factor_telegram_chat_id = '';

        if ($enabled && $method === 'google_auth_app') {
            $user->email_verified_at = now();
        }

        $user->save();

        if ($wasEnabled !== $enabled || $wasMethod !== $method) {
            $this->sendSecurityStateEmail($user, $enabled, $method);
        }

        return Redirect::route('profile.edit')->with('status', 'Two-factor settings saved.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function sendSecurityStateEmail($user, bool $enabled, string $method): void
    {
        $state = $enabled ? 'enabled' : 'disabled';
        $methodLabel = match ($method) {
            'email' => 'Email verification',
            'google_auth_app' => 'Google Authenticator',
            default => 'Security verification',
        };

        Mail::raw(
            "{$methodLabel} has been {$state} for your dPanel account.\n\nIf you did not make this change, please review your account security immediately.",
            static function ($message) use ($user): void {
                $message->to($user->email)
                    ->subject('dPanel security change notification');
            }
        );
    }
}
