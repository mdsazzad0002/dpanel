<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DatabaseUser extends Model
{
    protected $table = 'database_users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'username',
        'host',
        'password_hash',
        'server_id',
        'assigned_user_id',
        'assigned_reseller_id',
        'status',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function privileges(): HasMany
    {
        return $this->hasMany(DatabaseUserPrivilege::class);
    }
}
