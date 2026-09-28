<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityScan extends Model
{
    protected $fillable = [
        'server_id', 'website_id', 'scan_type', 'status', 'requested_by', 'started_at', 'completed_at',
        'files_scanned', 'threats_found', 'risk_score', 'summary', 'error',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'files_scanned' => 'integer',
        'threats_found' => 'integer',
        'risk_score' => 'integer',
        'summary' => 'array',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SecurityFinding::class, 'scan_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
