<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteGitDeployment extends Model
{
    use HasUuids;

    protected $fillable = [
        'website_id', 'repository_url', 'repository_full_name', 'branch', 'provider', 'github_account_id',
        'auth_username', 'auth_token', 'auto_action', 'interval_minutes', 'enabled', 'last_synced_at',
        'last_status', 'last_message', 'next_sync_at', 'created_by', 'webhook_secret', 'github_hook_id',
        'deploy_on_push',
    ];

    protected $hidden = ['auth_token', 'webhook_secret'];

    protected $casts = [
        'auth_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'enabled' => 'boolean',
        'deploy_on_push' => 'boolean',
        'last_synced_at' => 'datetime',
        'next_sync_at' => 'datetime',
    ];

    public function website(): BelongsTo { return $this->belongsTo(Website::class); }
    public function githubAccount(): BelongsTo { return $this->belongsTo(GithubAccount::class); }
    public function logs(): HasMany { return $this->hasMany(WebsiteGitLog::class, 'deployment_id'); }
}
