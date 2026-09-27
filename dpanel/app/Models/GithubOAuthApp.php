<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GithubOAuthApp extends Model
{
    use HasUuids;

    protected $table = 'github_oauth_apps';

    protected $fillable = ['name', 'client_id', 'client_secret', 'is_active', 'created_by'];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /** The app every "Connect GitHub" button uses; null until an admin sets it up. */
    public static function current(): ?self
    {
        return static::query()->where('is_active', true)->latest()->first();
    }
}
