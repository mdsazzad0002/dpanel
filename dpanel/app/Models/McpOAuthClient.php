<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OAuth client that registered itself through dynamic client
 * registration (RFC 7591) to reach the MCP endpoint.
 */
class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    protected $fillable = [
        'client_id',
        'client_secret_hash',
        'client_name',
        'redirect_uris',
        'token_endpoint_auth_method',
        'registered_ip',
    ];

    protected $hidden = [
        'client_secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(McpAccessToken::class, 'oauth_client_id');
    }

    public function isConfidential(): bool
    {
        return $this->client_secret_hash !== null;
    }

    public function secretMatches(?string $secret): bool
    {
        return $this->client_secret_hash !== null
            && is_string($secret)
            && hash_equals($this->client_secret_hash, hash('sha256', $secret));
    }

    public function allowsRedirect(string $uri): bool
    {
        return in_array($uri, (array) $this->redirect_uris, true);
    }
}
