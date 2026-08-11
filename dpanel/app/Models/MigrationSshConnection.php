<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MigrationSshConnection extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'name', 'host', 'port', 'username', 'auth_type', 'password', 'private_key', 'key_passphrase'];

    protected $hidden = ['password', 'private_key', 'key_passphrase'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'private_key' => 'encrypted',
            'key_passphrase' => 'encrypted',
        ];
    }
}
