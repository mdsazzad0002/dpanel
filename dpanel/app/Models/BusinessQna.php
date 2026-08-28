<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessQna extends Model
{
    use HasUuids;

    protected $fillable = [
        'business_product_id',
        'question',
        'answer',
        'sort_order',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(BusinessProduct::class, 'business_product_id');
    }
}
