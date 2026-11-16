<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named allow-list of MCP tools ("Monitoring only", "Site debugging", …).
 * Tokens tied to a group can only list and call these tools. Tools added to
 * the panel later are not included until an admin adds them to the group.
 */
class McpAccessGroup extends Model
{
    protected $fillable = [
        'name',
        'description',
        'tools',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(McpAccessToken::class, 'access_group_id');
    }

    public function allowsTool(string $name): bool
    {
        return in_array($name, $this->tools ?? [], true);
    }
}
