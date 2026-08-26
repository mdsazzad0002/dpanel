<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatChannel extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'name',
        'external_account_id',
        'chat_facebook_app_id',
        'credentials',
        'webhook_secret',
        'settings',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ChatContact::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class);
    }

    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ChatScheduledMessage::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function facebookApp(): BelongsTo
    {
        return $this->belongsTo(ChatFacebookApp::class, 'chat_facebook_app_id');
    }

    public function getBotToken(): ?string
    {
        $credentials = $this->credentials;

        return is_array($credentials) ? ($credentials['bot_token'] ?? null) : null;
    }

    public function getPageAccessToken(): ?string
    {
        $credentials = $this->credentials;

        return is_array($credentials) ? ($credentials['page_access_token'] ?? null) : null;
    }

    public function getWhatsAppAccessToken(): ?string
    {
        return is_array($this->credentials) ? ($this->credentials['access_token'] ?? null) : null;
    }

    public function getWhatsAppAppSecret(): ?string
    {
        return is_array($this->credentials) ? ($this->credentials['app_secret'] ?? null) : null;
    }

    public function getWhatsAppBusinessAccountId(): ?string
    {
        return is_array($this->credentials) ? ($this->credentials['business_account_id'] ?? null) : null;
    }

    public function getInstagramAccessToken(): ?string
    {
        return is_array($this->credentials) ? ($this->credentials['access_token'] ?? null) : null;
    }

    public function getInstagramAppSecret(): ?string
    {
        return is_array($this->credentials) ? ($this->credentials['app_secret'] ?? null) : null;
    }

    public function getSlackBotToken(): ?string
    {
        return is_array($this->credentials) ? ($this->credentials['bot_token'] ?? null) : null;
    }

    public function getSlackSigningSecret(): ?string
    {
        return is_array($this->credentials) ? ($this->credentials['signing_secret'] ?? null) : null;
    }

    public function isAutoReplyEnabled(): bool
    {
        $settings = $this->settings;

        return is_array($settings) ? (bool) ($settings['auto_reply_enabled'] ?? true) : true;
    }


    public function systemPrompt(): string
    {
        $settings = $this->settings;
        $prompt = is_array($settings) ? ($settings['system_prompt'] ?? null) : null;

        return $prompt ?: config('chatengine.default_system_prompt');
    }
}
