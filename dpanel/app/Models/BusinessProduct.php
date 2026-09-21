<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessProduct extends Model
{
    use HasUuids;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'sort_order',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function qnas(): HasMany
    {
        return $this->hasMany(BusinessQna::class)->orderBy('sort_order');
    }
}
