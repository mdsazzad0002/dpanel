<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseUserPrivilege extends Model
{
    protected $table = 'database_user_privileges';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'database_user_id',
        'database_name',
        'privileges',
    ];

    protected $casts = [
        'privileges' => 'array',
    ];

    public function databaseUser(): BelongsTo
    {
        return $this->belongsTo(DatabaseUser::class);
    }
}
