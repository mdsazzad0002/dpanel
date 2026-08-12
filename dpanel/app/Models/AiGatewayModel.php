<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGatewayModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'name',
        'display_name',
        'context_window',
        'max_output_tokens',
        'capabilities',
        'input_price',
        'output_price',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'context_window' => 'integer',
            'max_output_tokens' => 'integer',
            'input_price' => 'float',
            'output_price' => 'float',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiGatewayProvider::class, 'provider_id');
    }
}
