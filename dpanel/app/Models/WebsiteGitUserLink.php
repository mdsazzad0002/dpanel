<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One panel user's own credentials for a website's Git connection. */
class WebsiteGitUserLink extends Model
{
    use HasUuids;

    protected $fillable = ['deployment_id', 'user_id', 'github_account_id', 'auth_username', 'auth_token'];

    protected $hidden = ['auth_token'];

    protected $casts = ['auth_token' => 'encrypted'];

    public function deployment(): BelongsTo { return $this->belongsTo(WebsiteGitDeployment::class, 'deployment_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function githubAccount(): BelongsTo { return $this->belongsTo(GithubAccount::class); }
}
