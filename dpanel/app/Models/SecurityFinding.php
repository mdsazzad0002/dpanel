<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityFinding extends Model
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    protected $fillable = [
        'scan_id', 'website_id', 'rule_id', 'severity', 'category', 'title', 'description', 'file_path',
        'line_number', 'evidence', 'recommendation', 'status', 'auto_fix_available', 'fingerprint',
        'first_seen_at', 'last_seen_at', 'fixed_at', 'status_changed_by',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'auto_fix_available' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'fixed_at' => 'datetime',
    ];

    public static function fingerprintFor(?string $websiteId, string $ruleId, ?string $filePath): string
    {
        return sha1(($websiteId ?? 'server').'|'.$ruleId.'|'.($filePath ?? ''));
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(SecurityScan::class, 'scan_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
