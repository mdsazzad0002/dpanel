<?php

namespace App\Models;

use App\Models\Scopes\HumanUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An installed extension (backlog U7, plans/extension-spec.md). It runs
 * outside the panel and acts only through its own API token, owned by its
 * service account and limited to `granted_scopes`.
 */
class Extension extends Model
{
    protected $fillable = [
        'identifier',
        'name',
        'version',
        'description',
        'author',
        'homepage',
        'manifest',
        'granted_scopes',
        'events',
        'ui_title',
        'ui_url',
        'webhook_url',
        'webhook_secret',
        'enabled',
        'service_user_id',
        'installed_by',
        'last_delivery_at',
        'last_delivery_status',
        'last_delivery_error',
    ];

    protected $hidden = [
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'granted_scopes' => 'array',
            'events' => 'array',
            'webhook_secret' => 'encrypted',
            'enabled' => 'boolean',
            'last_delivery_at' => 'datetime',
            'last_delivery_status' => 'integer',
        ];
    }

    public function serviceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_user_id')->withoutGlobalScope(HumanUserScope::class);
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function token(): ?ApiToken
    {
        return ApiToken::query()->where('user_id', $this->service_user_id)->first();
    }

    /** @return list<string> */
    public function grantedScopes(): array
    {
        return array_values((array) $this->granted_scopes);
    }

    public function wantsEvent(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }
}
