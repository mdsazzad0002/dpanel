<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteRedirectRule extends Model
{
    public const KINDS = ['http_to_https', 'www_to_root', 'to_domain'];

    public const STATUS_CODES = [301, 302, 307, 308];

    protected $fillable = [
        'website_id',
        'name',
        'kind',
        'target',
        'status_code',
        'preserve_query',
        'enabled',
        'position',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'preserve_query' => 'boolean',
        'enabled' => 'boolean',
        'position' => 'integer',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'website_id');
    }

    /** @return array<string, mixed> */
    public function toRule(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'target' => $this->target,
            'status_code' => $this->status_code,
            'preserve_query' => $this->preserve_query,
            'enabled' => $this->enabled,
        ];
    }
}
