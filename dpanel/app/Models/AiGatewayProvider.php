<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiGatewayProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'driver',
        'slug',
        'base_url',
        'credentials',
        'is_active',
        'weight',
        'rate_limit_per_minute',
        'config',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'array',
            'config' => 'array',
            'is_active' => 'boolean',
            'weight' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiGatewayModel::class, 'provider_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The model a connection test or an unpinned request uses: the active
     * one with the highest priority, healthiest first, oldest on a tie.
     */
    public function preferredModel(): ?AiGatewayModel
    {
        return $this->models()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('failure_count')
            ->orderBy('id')
            ->get()
            ->first(fn (AiGatewayModel $m) => ! $m->isInCooldown() && ! \App\Services\AiGateway\Router::isSpecialOnly($m));
    }

    public function getApiKey(): ?string
    {
        $credentials = $this->credentials;

        return is_array($credentials) ? ($credentials['api_key'] ?? null) : null;
    }

    public function getDriverLabel(): string
    {
        return match ($this->driver) {
            'anthropic' => 'Claude (Anthropic)',
            'openai' => 'OpenAI',
            'openrouter' => 'OpenRouter',
            'groq' => 'Groq',
            'deepseek' => 'DeepSeek',
            'mistral' => 'Mistral',
            'cerebras' => 'Cerebras',
            'kilo' => 'Kilo Code',
            'gemini' => 'Google Gemini',
            default => ucfirst($this->driver),
        };
    }
}
