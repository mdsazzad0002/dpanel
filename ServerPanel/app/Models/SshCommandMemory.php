<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SshCommandMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'command',
        'context',
        'success_output_sample',
        'error_signature',
        'category',
        'tags',
        'success_count',
        'fail_count',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'success_count' => 'integer',
            'fail_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }
}
