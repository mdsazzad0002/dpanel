<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailPlan extends Model
{
    protected $fillable = [
        'id',
        'name',
        'slug',
        'max_storage_mb',
        'max_mailboxes',
        'allow_forwarding',
        'allow_aliases',
        'priority_support',
        'sort_order',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'allow_forwarding' => 'boolean',
        'allow_aliases' => 'boolean',
        'priority_support' => 'boolean',
        'max_storage_mb' => 'integer',
        'max_mailboxes' => 'integer',
        'sort_order' => 'integer',
    ];

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function mailboxCount(): int
    {
        return $this->mailboxes()->count();
    }

    public function totalStorageMb(): int
    {
        return (int) $this->mailboxes()->sum('quota_mb');
    }
}
