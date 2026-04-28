<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'input_text',
        'normalized_input',
        'response_text',
        'command_used',
        'output_sample',
        'tags',
        'usage_count',
        'success_count',
        'fail_count',
        'priority',
        'confidence',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'usage_count' => 'integer',
            'success_count' => 'integer',
            'fail_count' => 'integer',
            'priority' => 'integer',
            'confidence' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }
}
