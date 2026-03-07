<?php

namespace App\Http\Controllers;

use App\Models\Package;
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
     * Show users and create form.
     */
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $assignableRoles = $this->assignableRoles($actor);

        $users = User::query()
            ->with(['roles:id,name', 'package:id,name,slug', 'reseller:id,name,email'])
            ->when($actor?->hasRole('reseller'), function ($query) use ($actor) {
                $query->where('reseller_id', $actor?->id);
            })
            ->latest('id')
            ->get(['id', 'name', 'email', 'reseller_id', 'package_id', 'created_at'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'package' => $user->package ? [
                    'id' => $user->package->id,
                    'name' => $user->package->name,
                    'slug' => $user->package->slug,
                ] : null,
                'reseller' => $user->reseller ? [
                    'id' => $user->reseller->id,
                    'name' => $user->reseller->name,
                    'email' => $user->reseller->email,
                ] : null,
                'created_at' => optional($user->created_at)->toDateTimeString(),
            ])
            ->values()
            ->all();

        return Inertia::render('Users/Manage', [
            'users' => $users,
            'assignableRoles' => $assignableRoles,
            'packages' => Package::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
            'resellers' => Role::query()->where('name', 'reseller')->exists()
                ? User::query()
                    ->role('reseller')
                    ->orderBy('name')
                    ->get(['id', 'name', 'email'])
                : collect(),
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
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'reseller_id' => ['nullable', 'integer', 'exists:users,id'],
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
            'package_id' => $validated['package_id'] ?? null,
            'reseller_id' => $resellerId,
        ]);

        Role::findOrCreate($validated['role']);
        $user->syncRoles([$validated['role']]);

        return back()->with('success', 'User created successfully.');
    }

    /**
     * @return array<int, string>
     */
    private function assignableRoles(?User $actor): array
    {
        if ($actor?->hasRole('reseller')) {
            return ['general_user'];
        }

        $roles = Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        if (empty($roles)) {
            Role::findOrCreate('admin');
            return ['admin'];
        }

        return $roles;
    }
}
