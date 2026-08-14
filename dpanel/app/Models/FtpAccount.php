<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FtpAccount extends Model
{
    protected $fillable = ['website_id', 'username', 'directory', 'status'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
