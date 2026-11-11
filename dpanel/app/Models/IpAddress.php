<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One address of the server's IP pool (backlog U5). */
class IpAddress extends Model
{
    public const MODES = ['shared', 'dedicated'];

    protected $fillable = ['address', 'public_address', 'interface', 'label', 'mode', 'reseller_id', 'user_id', 'is_main', 'default_website_id'];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'reseller_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /** What DNS gets: the NAT address when there is one. */
    public function dnsAddress(): string
    {
        return (string) ($this->public_address ?: $this->address);
    }

    public function isDedicated(): bool
    {
        return $this->mode === 'dedicated';
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class, 'ip_address_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }
}
