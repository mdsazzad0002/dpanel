<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGatewayRequestLog extends Model
{
    use HasFactory;

    protected $table = 'ai_gateway_request_logs';

    protected $fillable = [
        'trace_id',
        'channel',
        'provider_id',
        'model_id',
        'operation',
        'model',
        'status',
        'request_payload',
        'response_snippet',
        'error_message',
        'input_tokens',
        'output_tokens',
        'cost',
        'latency_ms',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'float',
            'latency_ms' => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiGatewayProvider::class, 'provider_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiGatewayModel::class, 'model_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
