<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            ->with(['roles:id,name', 'reseller:id,name,email'])
            ->when($roleFilter === 'admin', fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'admin')))
            ->when($roleFilter === 'reseller', fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'reseller')))
            ->when($roleFilter === 'general', fn ($query) => $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['general', 'general_user'])))
            ->latest('id')
            ->paginate(30, [
                'id',
                'name',
                'email',
                'reseller_id',
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
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('Users/Create', [
            'assignableRoles' => $this->assignableRoles($actor),
            'resellers' => $this->availableResellers(),
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        $actor = $request->user();
        if ($actor?->hasRole('reseller') && (int) $user->reseller_id !== (int) $actor->id) {
            abort(403);
        }

        return Inertia::render('Users/Edit', [
            'assignableRoles' => $this->assignableRoles($actor),
            'resellers' => $this->availableResellers(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $this->normalizeRoleName($user->roles()->value('name')),
                'reseller_id' => $user->reseller_id,
                'disk_space_mb_limit' => $user->disk_space_mb_limit,
                'mail_accounts_limit' => $user->mail_accounts_limit,
                'databases_limit' => $user->databases_limit,
                'bandwidth_gb_limit' => $user->bandwidth_gb_limit,
                'websites_limit' => $user->websites_limit,
            ],
        ]);
    }

    /**
     * Create user from management panel.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $assignableRoles = $this->assignableRoles($actor);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', 'in:'.implode(',', $assignableRoles)],
            'reseller_id' => ['nullable', 'integer', 'exists:users,id'],
            'disk_space_mb_limit' => ['nullable', 'integer', 'min:0'],
            'mail_accounts_limit' => ['nullable', 'integer', 'min:0'],
            'databases_limit' => ['nullable', 'integer', 'min:0'],
            'bandwidth_gb_limit' => ['nullable', 'integer', 'min:0'],
            'websites_limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $resellerId = null;
        if ($actor?->hasRole('reseller')) {
            $resellerId = $actor->id;
        } elseif ($validated['role'] !== 'reseller') {
            $resellerId = $validated['reseller_id'] ?? null;
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'reseller_id' => $resellerId,
            'disk_space_mb_limit' => $validated['disk_space_mb_limit'] ?? null,
            'mail_accounts_limit' => $validated['mail_accounts_limit'] ?? null,
            'databases_limit' => $validated['databases_limit'] ?? null,
            'bandwidth_gb_limit' => $validated['bandwidth_gb_limit'] ?? null,
            'websites_limit' => $validated['websites_limit'] ?? null,
        ]);

        Role::findOrCreate($validated['role']);
        $user->syncRoles([$validated['role']]);

        return redirect()->route('users.manage')->with('success', 'User created successfully.');
    }

    /**
     * Update user from management panel.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $assignableRoles = $this->assignableRoles($actor);

        if ($actor?->hasRole('reseller') && (int) $user->reseller_id !== (int) $actor->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', 'in:'.implode(',', $assignableRoles)],
            'reseller_id' => ['nullable', 'integer', 'exists:users,id'],
            'disk_space_mb_limit' => ['nullable', 'integer', 'min:0'],
            'mail_accounts_limit' => ['nullable', 'integer', 'min:0'],
            'databases_limit' => ['nullable', 'integer', 'min:0'],
            'bandwidth_gb_limit' => ['nullable', 'integer', 'min:0'],
            'websites_limit' => ['nullable', 'integer', 'min:0'],
        ]);

        $resellerId = null;
        if ($actor?->hasRole('reseller')) {
            $resellerId = $actor->id;
        } elseif ($validated['role'] !== 'reseller') {
            $resellerId = $validated['reseller_id'] ?? null;
        }

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'reseller_id' => $resellerId,
            'disk_space_mb_limit' => $validated['disk_space_mb_limit'] ?? null,
            'mail_accounts_limit' => $validated['mail_accounts_limit'] ?? null,
            'databases_limit' => $validated['databases_limit'] ?? null,
            'bandwidth_gb_limit' => $validated['bandwidth_gb_limit'] ?? null,
            'websites_limit' => $validated['websites_limit'] ?? null,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        $user->update($payload);
        $user->syncRoles([$validated['role']]);

        return redirect()->route('users.manage')->with('success', 'User updated successfully.');
    }

    public function updateSuspension(Request $request, User $user): RedirectResponse
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

    public function destroy(Request $request, User $user): RedirectResponse
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

    private function availableResellers()
    {
        if (! Role::query()->where('name', 'reseller')->exists()) {
            return collect();
        }

        return User::query()
            ->role('reseller')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
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
