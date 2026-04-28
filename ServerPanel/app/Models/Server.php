<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'auth_type',
        'encrypted_password',
        'encrypted_private_key',
        'encrypted_private_key_passphrase',
        'mode',
        'os_name',
        'os_version',
        'kernel',
        'architecture',
        'cpu_cores',
        'ram_total_mb',
        'disk_total_gb',
        'last_connected_at',
        'last_scan_at',
        'status',
        'error_message',
        'notes',
        'created_by',
    ];

    protected $hidden = [
        'encrypted_password',
        'encrypted_private_key',
        'encrypted_private_key_passphrase',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_password' => 'encrypted',
            'encrypted_private_key' => 'encrypted',
            'encrypted_private_key_passphrase' => 'encrypted',
            'cpu_cores' => 'integer',
            'ram_total_mb' => 'integer',
            'disk_total_gb' => 'integer',
            'last_connected_at' => 'datetime',
            'last_scan_at' => 'datetime',
        ];
    }

    protected function isRootUser(): Attribute
    {
        return Attribute::get(fn (): bool => strtolower($this->username) === 'root');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function connectionTests(): HasMany
    {
        return $this->hasMany(SshConnectionTest::class);
    }

    public function commandJobs(): HasMany
    {
        return $this->hasMany(CommandJob::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ServerTask::class);
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(AiErrorResolution::class);
    }
}
