<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteIpRule extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'website_id', 'rule_type', 'ip_address', 'created_by'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
