<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reading of an account's resource use; spec section 7. */
class AccountUsageSample extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'sampled_at', 'disk_mb', 'bandwidth_mb', 'cpu_percent', 'memory_mb'];

    protected $casts = [
        'sampled_at' => 'datetime',
        'disk_mb' => 'integer',
        'bandwidth_mb' => 'integer',
        'cpu_percent' => 'float',
        'memory_mb' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
