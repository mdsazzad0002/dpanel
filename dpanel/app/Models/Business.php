<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'industry',
        'description',
        'reply_language',
        'integration_base_url',
        'integration_api_key',
        'search_enabled',
        'order_enabled',
        'email_enabled',
        'sms_enabled',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'search_enabled' => 'boolean',
            'order_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(BusinessProduct::class)->orderBy('sort_order');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ChatChannel::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The AI-reply cap comes from the owner's package plan (null = unlimited),
     * so upgrading/downgrading a client's package immediately changes what
     * every one of their businesses is allowed to use, with no per-business
     * duplicate setting to keep in sync.
     */
    public function aiReplyLimit(): ?int
    {
        return $this->createdBy?->package?->max_ai_replies;
    }

    public function hasReachedAiReplyLimit(): bool
    {
        $limit = $this->aiReplyLimit();

        return $limit !== null && $this->ai_replies_used >= $limit;
    }

    public function recordAiReply(): void
    {
        $this->increment('ai_replies_used');
    }

    public function scopeVisibleTo(Builder $query, ?User $actor): Builder
    {
        if (! $actor) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->hasAnyRole(['admin', 'superadmin'])) {
            return $query;
        }

        if ($actor->hasRole('reseller')) {
            return $query->whereIn('created_by', $actor->managedUsers()->select('id')->union(
                User::query()->whereKey($actor->id)->select('id')
            ));
        }

        return $query->where('created_by', $actor->id);
    }
}
