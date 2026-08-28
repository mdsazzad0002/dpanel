<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\RolePermissions;
use App\Support\UserAccessCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleManagementController extends Controller
{
    /**
     * Roles and permissions management page. The permission pool itself is
     * fixed/hardcoded (App\Support\RolePermissions::PERMISSIONS) — the UI
     * only lets an admin pick which of those apply to a role, and create
     * new roles, via an offcanvas panel.
     */
    public function index(): Response
    {
        foreach (RolePermissions::ROLES as $roleName) {
            RolePermissions::ensureRole($roleName);
        }

        $userCounts = User::query()
            ->whereNotNull('role')
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        $roles = Role::query()
            ->orderByRaw('name IN ("admin","reseller","general") DESC')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions_csv
                    ? array_values(array_filter(array_map('trim', explode(',', $role->permissions_csv))))
                    : [],
                'users_count' => (int) ($userCounts[$role->name] ?? 0),
                'is_system' => in_array($role->name, RolePermissions::ROLES, true),
            ])
            ->values()
            ->all();

        return Inertia::render('Roles/Manage', [
            'roles' => $roles,
            'systemRoles' => RolePermissions::ROLES,
            'permissionGroups' => RolePermissions::GROUPS,
        ]);
    }

    /**
     * Create a new role with a chosen subset of the hardcoded permissions.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', RolePermissions::PERMISSIONS)],
        ]);

        Role::create([
            'name' => $validated['name'],
            'permissions_csv' => implode(',', $validated['permissions'] ?? []),
        ]);

        UserAccessCache::invalidate();

        return back()->with('success', 'Role created successfully.');
    }

    /**
     * Update a role's permission set (and name, for non-system roles).
     */
    public function update(Request $request, string $token, string $roleId): RedirectResponse
    {
        $role = Role::findOrFail($roleId);
        $isSystem = in_array($role->name, RolePermissions::ROLES, true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:roles,name,'.$role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', RolePermissions::PERMISSIONS)],
        ]);

        if ($isSystem && $validated['name'] !== $role->name) {
            return back()->with('error', 'System role names cannot be changed.');
        }

        $role->name = $validated['name'];
        $role->permissions_csv = implode(',', $validated['permissions'] ?? []);
        $role->save();

        UserAccessCache::invalidate();

        return back()->with('success', 'Role updated successfully.');
    }

    /**
     * Delete a custom (non-system) role.
     */
    public function destroy(string $token, string $roleId): RedirectResponse
    {
        $role = Role::findOrFail($roleId);

        if (in_array($role->name, RolePermissions::ROLES, true)) {
            return back()->with('error', 'System roles cannot be deleted.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'Role is assigned to users and cannot be deleted.');
        }

        $role->delete();
        UserAccessCache::invalidate();

        return back()->with('success', 'Role deleted successfully.');
    }
}
