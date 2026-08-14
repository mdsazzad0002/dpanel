<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PackagePlan;
use App\Models\PanelSession;
use App\Models\User;
use App\Models\WhmcsAccount;
use App\Support\UserAccessCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class WhmcsController extends Controller
{
    public function handshake(): JsonResponse
    {
        return $this->ok(['server_time' => time(), 'version' => '1.0', 'capabilities' => ['plans', 'provision', 'change_plan', 'suspend', 'unsuspend', 'terminate', 'sso']]);
    }

    public function plans(): JsonResponse
    {
        return $this->ok(['plans' => PackagePlan::query()->whereNull('owner_user_id')->orderBy('sort_order')->get(['id', 'slug', 'name', 'max_storage_mb', 'max_mailboxes', 'max_websites', 'max_databases', 'max_bandwidth_gb'])]);
    }

    public function provision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:191'], 'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'], 'plan_slug' => ['required', 'string', 'max:100'],
        ]);
        $plan = PackagePlan::query()->whereNull('owner_user_id')->where('slug', $data['plan_slug'])->first();
        if (! $plan) return $this->error('Unknown plan slug.', 422);

        try {
            $account = DB::transaction(function () use ($data, $plan, $request): WhmcsAccount {
                $account = WhmcsAccount::query()->where('external_id', $data['external_id'])->lockForUpdate()->first();
                if (! $account) {
                    abort_if(User::query()->where('email', strtolower($data['email']))->exists(), 409, 'Email already belongs to a non-WHMCS account.');
                    $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'email_verified_at' => now(), 'password' => Str::password(40), 'package_id' => $plan->id]);
                    Role::findOrCreate('general');
                    $user->syncRoles(['general']);
                    $account = WhmcsAccount::create(['external_id' => $data['external_id'], 'user_id' => $user->id]);
                } else {
                    $user = $account->user;
                    abort_if(User::query()->where('email', strtolower($data['email']))->whereKeyNot($user->id)->exists(), 409, 'Email already in use.');
                    $user->update(['name' => $data['name'], 'email' => strtolower($data['email']), 'package_id' => $plan->id, 'is_suspended' => false, 'suspended_at' => null]);
                }
                $this->applyPlan($user, $plan);
                $account->update(['status' => 'active', 'last_request_id' => $request->attributes->get('whmcs_request_id')]);
                return $account->fresh('user.package');
            }, 3);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
        UserAccessCache::invalidate();
        return $this->ok(['account' => $this->accountData($account)]);
    }

    public function lifecycle(Request $request, string $action): JsonResponse
    {
        $data = $request->validate(['external_id' => ['required', 'string', 'max:191'], 'plan_slug' => ['nullable', 'string', 'max:100']]);
        $account = WhmcsAccount::query()->where('external_id', $data['external_id'])->first();
        if (! $account) return $this->error('Account not found.', 404);
        $user = $account->user;
        if ($action === 'change-plan') {
            $plan = PackagePlan::query()->whereNull('owner_user_id')->where('slug', $data['plan_slug'] ?? '')->first();
            if (! $plan) return $this->error('Unknown plan slug.', 422);
            $this->applyPlan($user, $plan);
        } elseif ($action === 'suspend' || $action === 'terminate') {
            $user->update(['is_suspended' => true, 'suspended_at' => now()]);
            PanelSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $account->update(['status' => $action === 'terminate' ? 'terminated' : 'suspended']);
        } elseif ($action === 'unsuspend') {
            $user->update(['is_suspended' => false, 'suspended_at' => null]);
            $account->update(['status' => 'active']);
        } else return $this->error('Unsupported action.', 404);
        $account->update(['last_request_id' => $request->attributes->get('whmcs_request_id')]);
        return $this->ok(['account' => $this->accountData($account->fresh('user.package'))]);
    }

    public function issueSso(Request $request): JsonResponse
    {
        $data = $request->validate(['external_id' => ['required', 'string', 'max:191']]);
        $account = WhmcsAccount::query()->where('external_id', $data['external_id'])->where('status', 'active')->first();
        if (! $account || $account->user->is_suspended) return $this->error('Active account not found.', 404);
        $plain = bin2hex(random_bytes(32));
        $ttl = max(30, min(300, (int) config('whmcs.sso_ttl', 60)));
        Cache::put('whmcs:sso:'.hash('sha256', $plain), ['user_id' => $account->user_id], $ttl);
        return $this->ok(['url' => route('whmcs.sso.consume', ['token' => $plain], absolute: true), 'expires_in' => $ttl]);
    }

    public function consumeSso(Request $request, string $token): RedirectResponse
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $payload = Cache::pull('whmcs:sso:'.hash('sha256', $token));
        $user = is_array($payload) ? User::find($payload['user_id'] ?? null) : null;
        abort_if(! $user || $user->is_suspended, 404);
        Auth::login($user);
        $request->session()->regenerate();
        $panelToken = bin2hex(random_bytes(32)); $cookieToken = bin2hex(random_bytes(32));
        $request->session()->put('panel_session_token', $panelToken);
        PanelSession::syncSingleSession((int) $user->id, $panelToken, $cookieToken, (string) $request->ip(), (string) $request->userAgent(), PanelSession::initialExpiresAt(), now());
        $cookie = cookie(config('serverpanel.panel_cookie_name', 'panel_session_proof'), $cookieToken, PanelSession::inactivityMinutes(), config('session.path', '/'), config('session.domain'), (bool) config('session.secure'), true, false, 'Lax');
        Cookie::queue($cookie);
        return redirect()->route('dashboard', ['token' => $panelToken])->withCookie($cookie);
    }

    private function applyPlan(User $user, PackagePlan $plan): void
    {
        $user->update(['package_id' => $plan->id, 'disk_space_mb_limit' => $plan->max_storage_mb, 'mail_accounts_limit' => $plan->max_mailboxes, 'websites_limit' => $plan->max_websites, 'databases_limit' => $plan->max_databases, 'bandwidth_gb_limit' => $plan->max_bandwidth_gb]);
    }
    private function accountData(WhmcsAccount $a): array { return ['external_id' => $a->external_id, 'status' => $a->status, 'user_id' => $a->user_id, 'email' => $a->user->email, 'plan_slug' => $a->user->package?->slug]; }
    private function ok(array $data = []): JsonResponse { return response()->json(['ok' => true, ...$data])->header('Cache-Control', 'no-store'); }
    private function error(string $message, int $status): JsonResponse { return response()->json(['ok' => false, 'message' => $message], $status)->header('Cache-Control', 'no-store'); }
}
