<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\MailPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    /**
     * Show users list.
     */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $search = trim((string) $request->query('search', ''));
        $statusFilter = $this->normalizeStatusFilter($request->query('status'));

        $roleFilter = $this->normalizeRoleFilter(
            $request->query('role'),
            $request->route()?->getName()
        );

        $scopedQuery = User::query()
            ->when($actor?->hasRole('reseller'), function ($query) use ($actor) {
                $query->where('reseller_id', $actor?->id);
            })
            ->when($actor && ($actor->hasRole('general') || $actor->hasRole('general_user')), function ($query) use ($actor) {
                $query->where('id', $actor->id);
            });

        $filteredScopeQuery = (clone $scopedQuery)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($statusFilter === 'active', fn ($query) => $query->where('is_suspended', false))
            ->when($statusFilter === 'suspended', fn ($query) => $query->where('is_suspended', true));

        $roleCounts = [
            'all' => (clone $filteredScopeQuery)->count(),
            'admin' => (clone $filteredScopeQuery)->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->count(),
            'reseller' => (clone $filteredScopeQuery)->whereHas('roles', fn ($q) => $q->where('name', 'reseller'))->count(),
            'general' => (clone $filteredScopeQuery)->whereHas('roles', fn ($q) => $q->whereIn('name', ['general', 'general_user']))->count(),
        ];

        $users = (clone $filteredScopeQuery)
            ->with(['roles:id,name', 'reseller:id,name,email', 'package:id,name'])
            ->when($roleFilter === 'admin', fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'admin')))
            ->when($roleFilter === 'reseller', fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'reseller')))
            ->when($roleFilter === 'general', fn ($query) => $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['general', 'general_user'])))
            ->latest('id')
            ->paginate(30, [
                'id',
                'name',
                'email',
                'reseller_id',
                'package_id',
                'is_suspended',
                'suspended_at',
                'disk_space_mb_limit',
                'mail_accounts_limit',
                'databases_limit',
                'bandwidth_gb_limit',
                'websites_limit',
                'created_at',
            ])
            ->withQueryString();

        $users->getCollection()->transform(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles
                    ->pluck('name')
                    ->map(fn (string $role): string => $this->normalizeRoleName($role))
                    ->unique()
                    ->values()
                    ->all(),
                'reseller_id' => $user->reseller_id,
                'package_id' => $user->package_id,
                'package' => $user->package ? ['id' => $user->package->id, 'name' => $user->package->name] : null,
                'is_suspended' => (bool) $user->is_suspended,
                'suspended_at' => optional($user->suspended_at)->toDateTimeString(),
                'disk_space_mb_limit' => $user->disk_space_mb_limit,
                'mail_accounts_limit' => $user->mail_accounts_limit,
                'databases_limit' => $user->databases_limit,
                'bandwidth_gb_limit' => $user->bandwidth_gb_limit,
                'websites_limit' => $user->websites_limit,
                'reseller' => $user->reseller ? [
                    'id' => $user->reseller->id,
                    'name' => $user->reseller->name,
                    'email' => $user->reseller->email,
                ] : null,
                'created_at' => optional($user->created_at)->toDateTimeString(),
            ]);

        return Inertia::render('Users/Manage', [
            'users' => $users,
            'activeRoleFilter' => $roleFilter,
            'roleCounts' => $roleCounts,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter ?? 'all',
            ],
            'assignableRoles' => $this->assignableRoles($actor),
            'packages' => $this->availablePackages($actor),
        ]);
    }

    /**
     * Create user from management panel.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $assignableRoles = $this->assignableRoles($actor);
        $packageRule = Rule::exists('mail_plans', 'id');
        if ($actor?->hasRole('reseller')) {
            $packageRule->where(fn ($query) => $query->where('owner_user_id', $actor->id));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', 'in:'.implode(',', $assignableRoles)],
            'package_id' => ['nullable', 'required_if:role,general', 'string', $packageRule],
        ]);

        $resellerId = $actor?->hasRole('reseller') ? $actor->id : null;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
            'reseller_id' => $resellerId,
            'package_id' => $validated['package_id'] ?? null,
        ]);

        $this->applyPackageLimits($user);

        Role::findOrCreate($validated['role']);
        $user->syncRoles([$validated['role']]);

        return redirect()->route('users.manage')->with('success', 'User created successfully.');
    }

    /**
     * Update user from management panel.
     */
    public function update(Request $request, string $token, User $user): RedirectResponse
    {
        $actor = $request->user();
        $assignableRoles = $this->assignableRoles($actor);
        $packageRule = Rule::exists('mail_plans', 'id');
        if ($actor?->hasRole('reseller')) {
            $packageRule->where(fn ($query) => $query->where('owner_user_id', $actor->id));
        }

        if ($actor?->hasRole('reseller') && (int) $user->reseller_id !== (int) $actor->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', 'in:'.implode(',', $assignableRoles)],
            'package_id' => ['nullable', 'required_if:role,general', 'string', $packageRule],
        ]);

        $resellerId = $actor?->hasRole('reseller')
            ? $actor->id
            : ($validated['role'] === 'reseller' ? null : $user->reseller_id);

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'reseller_id' => $resellerId,
            'package_id' => $validated['package_id'] ?? null,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        $user->update($payload);
        $this->applyPackageLimits($user);
        $user->syncRoles([$validated['role']]);

        return redirect()->route('users.manage')->with('success', 'User updated successfully.');
    }

    public function updateSuspension(Request $request, string $token, User $user): RedirectResponse
    {
        $actor = $request->user();
        if ($actor?->hasRole('reseller') && (int) $user->reseller_id !== (int) $actor->id) {
            abort(403);
        }
        if ((int) $actor?->id === (int) $user->id) {
            return back()->with('error', 'You cannot suspend your own account.');
        }

        $validated = $request->validate([
            'suspend' => ['required', 'boolean'],
        ]);

        $suspend = (bool) $validated['suspend'];
        $user->is_suspended = $suspend;
        $user->suspended_at = $suspend ? now() : null;
        $user->save();

        return back()->with('success', $suspend ? 'User suspended successfully.' : 'User unsuspended successfully.');
    }

    public function destroy(Request $request, string $token, User $user): RedirectResponse
    {
        $actor = $request->user();
        if ($actor?->hasRole('reseller') && (int) $user->reseller_id !== (int) $actor->id) {
            abort(403);
        }
        if ((int) $actor?->id === (int) $user->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return back()->with('success', 'User deleted successfully.');
    }

    /**
     * @return array<int, string>
     */
    private function assignableRoles(?User $actor): array
    {
        if ($actor?->hasRole('reseller')) {
            return ['general'];
        }

        $roles = ['general', 'admin', 'reseller'];
        foreach ($roles as $role) {
            Role::findOrCreate($role);
        }

        return $roles;
    }

    private function availablePackages(?User $actor)
    {
        if (! $actor?->hasAnyRole(['admin', 'superadmin', 'reseller']) && ! $actor?->can('manage_packages')) {
            return collect();
        }

        return MailPlan::query()
            ->when($actor?->hasRole('reseller'), fn ($query) => $query->where('owner_user_id', $actor->id))
            ->orderBy('sort_order')->orderBy('name')->get([
            'id', 'name', 'max_storage_mb', 'max_mailboxes', 'max_websites',
            'max_databases', 'max_bandwidth_gb',
        ]);
    }

    private function applyPackageLimits(User $user): void
    {
        $package = $user->package()->first();
        if ($package === null) {
            $user->forceFill([
                'disk_space_mb_limit' => null,
                'mail_accounts_limit' => null,
                'websites_limit' => null,
                'databases_limit' => null,
                'bandwidth_gb_limit' => null,
            ])->save();
            return;
        }

        $user->forceFill([
            'disk_space_mb_limit' => $package->max_storage_mb,
            'mail_accounts_limit' => $package->max_mailboxes,
            'websites_limit' => $package->max_websites,
            'databases_limit' => $package->max_databases,
            'bandwidth_gb_limit' => $package->max_bandwidth_gb,
        ])->save();
    }

    private function normalizeRoleName(?string $role): string
    {
        if ($role === 'general_user') {
            return 'general';
        }

        return $role ?: 'general';
    }

    private function normalizeRoleFilter(mixed $roleFilter, ?string $routeName): ?string
    {
        $raw = is_string($roleFilter) ? strtolower(trim($roleFilter)) : '';
        if ($raw === '') {
            $raw = match ($routeName) {
                'admin.panel' => 'admin',
                'reseller.panel' => 'reseller',
                'user.panel' => 'general',
                default => '',
            };
        }

        if ($raw === 'general_user') {
            return 'general';
        }

        if (in_array($raw, ['admin', 'reseller', 'general'], true)) {
            return $raw;
        }

        return null;
    }

    private function normalizeStatusFilter(mixed $status): ?string
    {
        $raw = is_string($status) ? strtolower(trim($status)) : '';

        if (in_array($raw, ['active', 'suspended'], true)) {
            return $raw;
        }

        return null;
    }
}
