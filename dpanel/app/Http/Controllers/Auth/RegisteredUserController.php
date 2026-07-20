<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PanelSession;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        Role::findOrCreate('general');
        $user->assignRole('general');

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        $urlToken = bin2hex(random_bytes(32));
        $cookieToken = bin2hex(random_bytes(32));
        $lifetime = max(1, (int) config('serverpanel.panel_token_lifetime', config('session.lifetime', 120)));
        $cookieName = (string) config('serverpanel.panel_cookie_name', 'panel_session_proof');

        PanelSession::syncSingleSession(
            userId: (int) $user->id,
            token: $urlToken,
            cookieToken: $cookieToken,
            ipAddress: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
            expiresAt: now()->addMinutes($lifetime),
            lastSeenAt: now(),
        );

        $request->session()->put('panel_session_token', $urlToken);
        URL::defaults(['token' => $urlToken]);

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

        \Illuminate\Support\Facades\Cookie::queue($panelCookie);

        return redirect(route('dashboard', absolute: false))
            ->withCookie($panelCookie);
    }
}
