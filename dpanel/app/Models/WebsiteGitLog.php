<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteGitLog extends Model
{
    use HasUuids;

    protected $fillable = ['deployment_id', 'action', 'status', 'message', 'triggered_by'];

    public function deployment(): BelongsTo { return $this->belongsTo(WebsiteGitDeployment::class, 'deployment_id'); }
}
