<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One user of a password-protected folder of a website (task S5). */
class WebsiteProtectedDirectory extends Model
{
    protected $fillable = ['website_id', 'path', 'realm', 'username', 'password_hash'];

    protected $hidden = ['password_hash'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
