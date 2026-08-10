<?php

namespace App\Http\Controllers;

use App\Support\UserAccessCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\ValidationException;

class RoleManagementController extends Controller
{
    /**
     * Role create page.
     */
    public function create(): Response
    {
        $assignerPermissions = request()->user()->getAllPermissions()->pluck('name');

        return Inertia::render('Roles/Create', [
            'permissions' => Permission::query()
                ->whereIn('name', $assignerPermissions)
                ->orderBy('priority')
                ->orderBy('name')
                ->pluck('name')
                ->values()
                ->all(),
        ]);
    }

    /**
     * Roles and permissions management page.
     */
    public function index(): Response
    {
        $roles = Role::query()
            ->with(['permissions:id,name', 'users:id'])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'users_count' => $role->users->count(),
                'is_system' => in_array($role->name, $this->systemRoles(), true),
            ])
            ->values()
            ->all();

        return Inertia::render('Roles/Manage', [
            'roles' => $roles,
            'systemRoles' => $this->systemRoles(),
        ]);
    }

    /**
     * Role edit page.
     */
    public function edit(string $token, Role $role): Response
    {
        $role->load('permissions:id,name');
        $permissions = Permission::query()->orderBy('priority')->orderBy('name')->get(['name', 'priority']);

        return Inertia::render('Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'is_system' => in_array($role->name, $this->systemRoles(), true),
            ],
            'permissions' => $permissions->pluck('name')->values()->all(),
            'permissionPriorities' => $permissions->pluck('priority', 'name')->all(),
            'assignablePermissions' => request()->user()->getAllPermissions()->pluck('name')->values()->all(),
            'systemRoles' => $this->systemRoles(),
        ]);
    }

    /**
     * Create a new role and assign permissions.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $selectedPermissions = $validated['permissions'] ?? [];
        $assignerPermissions = $request->user()->getAllPermissions()->pluck('name')->all();
        $unownedPermissions = array_values(array_diff($selectedPermissions, $assignerPermissions));

        if ($unownedPermissions !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'You can only assign permissions that you already have: '.implode(', ', $unownedPermissions),
            ]);
        }

        $role = Role::create(['name' => $validated['name']]);
        $role->syncPermissions($selectedPermissions);
        UserAccessCache::invalidate();

        return back()->with('success', 'Role created successfully.');
    }

    /**
     * Update role and its permissions.
     */
    public function update(Request $request, string $token, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,'.$role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if ($validated['name'] !== $role->name) {
            return back()->with('error', 'Role names cannot be changed after creation.');
        }

        $selectedPermissions = $validated['permissions'] ?? [];
        $currentPermissions = $role->permissions()->pluck('name')->all();
        $newPermissions = array_values(array_diff($selectedPermissions, $currentPermissions));
        $assignerPermissions = $request->user()->getAllPermissions()->pluck('name')->all();
        $unownedPermissions = array_values(array_diff($newPermissions, $assignerPermissions));

        if ($unownedPermissions !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'You can only assign permissions that you already have: '.implode(', ', $unownedPermissions),
            ]);
        }

        // The admin system role always retains the complete permission set.
        if ($role->name === 'admin') {
            $selectedPermissions = Permission::query()->pluck('name')->all();
        }

        $role->name = $validated['name'];
        $role->save();
        $role->syncPermissions($selectedPermissions);
        UserAccessCache::invalidate();

        return back()->with('success', 'Role updated successfully.');
    }

    /**
     * Delete role.
     */
    public function destroy(string $token, Role $role): RedirectResponse
    {
        if (in_array($role->name, $this->systemRoles(), true)) {
            return back()->with('error', 'System roles cannot be deleted.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'Role is assigned to users and cannot be deleted.');
        }

        $role->delete();
        UserAccessCache::invalidate();

        return back()->with('success', 'Role deleted successfully.');
    }

    /**
     * @return array<int, string>
     */
    private function systemRoles(): array
    {
        return ['admin', 'reseller', 'general'];
    }

}
