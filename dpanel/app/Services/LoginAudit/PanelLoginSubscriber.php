<?php

namespace App\Services\LoginAudit;

use App\Models\LoginEvent;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;

/**
 * Records every panel sign-in and sign-out. Laravel fires Login for the
 * password form, the 2FA step, "remember me" cookies, impersonation and
 * WHMCS SSO alike; the route tells them apart. Failed attempts are recorded
 * where they fail (LoginRequest, TwoFactorChallengeController).
 */
class PanelLoginSubscriber
{
    public function __construct(private readonly LoginAuditRecorder $recorder) {}

    /** Resolved per event: the subscriber is built once at boot. */
    private function request(): Request
    {
        return request();
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'onLogin']);
        $events->listen(Logout::class, [self::class, 'onLogout']);
    }

    public function onLogin(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $actorId = $this->impersonatorId((int) $event->user->getAuthIdentifier());

        $this->recorder->record([
            'channel' => LoginEvent::CHANNEL_PANEL,
            'result' => LoginEvent::RESULT_SUCCESS,
            'method' => $actorId ? 'impersonation' : $this->method($event->remember),
            'user_id' => $event->user->getAuthIdentifier(),
            'username' => (string) ($event->user->email ?? ''),
            'actor_id' => $actorId,
            'ip_address' => (string) $this->request()->ip(),
            'user_agent' => (string) $this->request()->userAgent(),
        ] + ($actorId ? [] : DeviceLocation::from($this->request())));
        DeviceLocation::forget($this->request());
    }

    public function onLogout(Logout $event): void
    {
        if ($event->guard !== 'web' || $event->user === null) {
            return;
        }

        $this->recorder->record([
            'channel' => LoginEvent::CHANNEL_PANEL,
            'result' => LoginEvent::RESULT_LOGOUT,
            'method' => 'logout',
            'user_id' => $event->user->getAuthIdentifier(),
            'username' => (string) ($event->user->email ?? ''),
            'ip_address' => (string) $this->request()->ip(),
            'user_agent' => (string) $this->request()->userAgent(),
        ]);
    }

    /** The admin who is signing in as someone else, if that is what this login is. */
    private function impersonatorId(int $userId): ?int
    {
        if (! $this->request()->hasSession()) {
            return null;
        }
        $adminId = (int) $this->request()->session()->get('impersonation.admin_id', 0);

        return $adminId > 0 && $adminId !== $userId ? $adminId : null;
    }

    private function method(bool $remember): string
    {
        // The POST /login route has no name, so go by path.
        $path = '/'.trim($this->request()->path(), '/');

        return match (true) {
            str_contains($path, '/two-factor-challenge') => 'password + 2FA',
            str_contains($path, '/whmcs/sso/') => 'WHMCS SSO',
            str_ends_with($path, '/register') => 'registration',
            str_ends_with($path, '/login') => 'password',
            $remember => 'remember-me cookie',
            default => 'session switch',
        };
    }
}
