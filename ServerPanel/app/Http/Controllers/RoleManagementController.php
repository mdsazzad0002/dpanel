<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleManagementController extends Controller
{
    /**
     * Role create page.
     */
    public function create(): Response
    {
        return Inertia::render('Roles/Create', [
            'permissions' => Permission::query()->orderBy('name')->pluck('name')->values()->all(),
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
    public function edit(Role $role): Response
    {
        $role->load('permissions:id,name');

        return Inertia::render('Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'is_system' => in_array($role->name, $this->systemRoles(), true),
            ],
            'permissions' => Permission::query()->orderBy('name')->pluck('name')->values()->all(),
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

        $role = Role::create(['name' => $validated['name']]);
        $role->syncPermissions($validated['permissions'] ?? []);

        return back()->with('success', 'Role created successfully.');
    }

    /**
     * Update role and its permissions.
     */
    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,'.$role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if (in_array($role->name, $this->systemRoles(), true) && $validated['name'] !== $role->name) {
            return back()->with('error', 'System role names cannot be changed.');
        }

        $role->name = $validated['name'];
        $role->save();
        $role->syncPermissions($validated['permissions'] ?? []);

        return back()->with('success', 'Role updated successfully.');
    }

    /**
     * Delete role.
     */
    public function destroy(Role $role): RedirectResponse
    {
        if (in_array($role->name, $this->systemRoles(), true)) {
            return back()->with('error', 'System roles cannot be deleted.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'Role is assigned to users and cannot be deleted.');
        }

        $role->delete();

        return back()->with('success', 'Role deleted successfully.');
    }

    /**
     * @return array<int, string>
     */
    private function systemRoles(): array
    {
        return ['admin'];
    }
}
