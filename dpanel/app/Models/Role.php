<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Custom role model (no Spatie). A role is just a name plus a
 * comma-separated permission list (permissions_csv), synced from the
 * hardcoded map in App\Support\RolePermissions.
 */
class Role extends Model
{
    protected $fillable = [
        'name',
        'permissions_csv',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'name');
    }

    public static function findOrCreate(string $name): self
    {
        return static::firstOrCreate(['name' => $name]);
    }
}
