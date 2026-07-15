<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'twoFactor' => [
                'enabled' => (bool) $request->user()->two_factor_enabled,
                'method' => (string) ($request->user()->two_factor_method ?? ''),
                'secret' => (string) ($request->user()->two_factor_secret ?? ''),
                'telegram_chat_id' => (string) ($request->user()->two_factor_telegram_chat_id ?? ''),
                'available_methods' => $this->twoFactor->availableMethods($request->user()),
                'global_policy' => $this->securitySettings->read()['two_factor'],
                'provisioning_uri' => $this->twoFactor->hasTotpSecret($request->user())
                    ? $this->twoFactor->buildProvisioningUri($request->user(), (string) $request->user()->two_factor_secret)
                    : '',
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    public function updateTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'method' => ['required', 'in:email,telegram,google_auth_app'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
        ]);

        if ((bool) $validated['enabled'] && (string) $validated['method'] === 'telegram' && trim((string) ($validated['telegram_chat_id'] ?? '')) === '') {
            return Redirect::back()->withErrors([
                'telegram_chat_id' => 'Telegram chat ID is required for Telegram 2FA.',
            ]);
        }

        if ((bool) $validated['enabled'] && (string) $validated['method'] === 'google_auth_app' && trim((string) $user->two_factor_secret) === '') {
            $user->two_factor_secret = $this->twoFactor->generateSecret();
        }

        $user->two_factor_enabled = (bool) $validated['enabled'];
        $user->two_factor_method = (string) $validated['method'];
        $user->two_factor_telegram_chat_id = trim((string) ($validated['telegram_chat_id'] ?? ''));
        $user->save();

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
}
