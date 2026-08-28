<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_suspended',
        'suspended_at',
        'role',
        'reseller_id',
        'package_id',
        'disk_space_mb_limit',
        'mail_accounts_limit',
        'databases_limit',
        'bandwidth_gb_limit',
        'websites_limit',
        'two_factor_enabled',
        'two_factor_method',
        'two_factor_secret',
        'two_factor_telegram_chat_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_telegram_start_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_suspended' => 'boolean',
            'suspended_at' => 'datetime',
            'disk_space_mb_limit' => 'integer',
            'mail_accounts_limit' => 'integer',
            'databases_limit' => 'integer',
            'bandwidth_gb_limit' => 'integer',
            'websites_limit' => 'integer',
            'two_factor_enabled' => 'boolean',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function managedUsers(): HasMany
    {
        return $this->hasMany(User::class, 'reseller_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PackagePlan::class, 'package_id');
    }

    public function ownedPackages(): HasMany
    {
        return $this->hasMany(PackagePlan::class, 'owner_user_id');
    }

    /**
     * Custom role/permission system (no Spatie). A user has a single
     * role name in the `role` column; the role's permissions live in
     * roles.permissions_csv, hardcoded/synced from App\Support\RolePermissions.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * @param array<int, string> $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->role !== null && in_array($this->role, $roles, true);
    }

    /**
     * @return Collection<int, string>
     */
    public function getRoleNames(): Collection
    {
        return $this->role ? collect([$this->role]) : collect();
    }

    public function assignRole(?string $role): void
    {
        $this->role = $role;
        $this->save();
    }

    /**
     * @param array<int, string> $roles
     */
    public function syncRoles(array $roles): void
    {
        $this->assignRole($roles[0] ?? null);
    }

    /**
     * Permission names granted to this user's role, read from the
     * hardcoded static map in App\Support\RolePermissions (self-healing
     * if the role row is missing).
     *
     * @return array<int, string>
     */
    public function allPermissionNames(): array
    {
        if (! $this->role) {
            return [];
        }

        $role = \App\Support\RolePermissions::ensureRole($this->role);

        if (! $role || ! $role->permissions_csv) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $role->permissions_csv))));
    }

    /**
     * @return Collection<int, array{name: string}>
     */
    public function getAllPermissions(): Collection
    {
        return collect($this->allPermissionNames())->map(fn (string $name): array => ['name' => $name]);
    }

    public function hasAccess(string $permission): bool
    {
        return in_array($permission, $this->allPermissionNames(), true);
    }
}
