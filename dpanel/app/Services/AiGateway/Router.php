<?php

namespace App\Services\AiGateway;

use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves which provider + model should serve a given gateway request:
 * the owner of a specifically requested model, falling back to the
 * highest-weight active provider when no model is requested (or found).
 */
class Router
{
    /**
     * @param  array{model?: string|null}  $context
     * @return array{provider: AiGatewayProvider, model: AiGatewayModel|null, modelName: string}
     */
    public function resolve(array $context = []): array
    {
        $context = array_merge(['model' => null], $context);

        if (! empty($context['model'])) {
            $model = AiGatewayModel::query()
                ->where('name', $context['model'])
                ->whereHas('provider', fn ($q) => $q->where('is_active', true))
                ->with('provider')
                ->first();

            if ($model?->provider) {
                return $this->finalise($model->provider, $model, $context['model']);
            }
        }

        if (config('aigateway.fallback_to_first_active_provider', true)) {
            $provider = AiGatewayProvider::query()
                ->where('is_active', true)
                ->orderByDesc('weight')
                ->orderBy('id')
                ->first();

            if ($provider) {
                return $this->finalise($provider, null, $context['model']);
            }
        }

        throw Exceptions\AiGatewayException::noActiveProvider();
    }

    /**
     * Eligible failover candidates for a model name (or, if null, any
     * active model), optionally pinned to one provider: active model,
     * active provider, not in cooldown. Base order is by provider weight
     * with random jitter within equal weights; the list is then rotated
     * so each call starts from the next candidate in turn (round-robin),
     * spreading requests across providers instead of hammering the
     * top-weight one until it hits its rate limit.
     *
     * @return \Illuminate\Support\Collection<int, AiGatewayModel>
     */
    public function candidates(?string $modelName = null, ?int $providerId = null): \Illuminate\Support\Collection
    {
        $base = AiGatewayModel::query()
            ->where('is_active', true)
            ->whereHas('provider', fn ($q) => $q->where('is_active', true))
            ->when($modelName, fn ($q) => $q->where('name', $modelName))
            ->when($providerId, fn ($q) => $q->where('provider_id', $providerId))
            ->with('provider')
            ->get()
            ->filter(fn (AiGatewayModel $m) => ! $m->isInCooldown())
            ->sortByDesc(fn (AiGatewayModel $m) => $m->provider->weight * 1000 + random_int(0, 999))
            ->values();

        $count = $base->count();
        if ($count <= 1) {
            return $base;
        }

        $cacheKey = 'aigateway:router:rr:'.($providerId ?: 'any').':'.($modelName ?: 'any');
        $cursor = Cache::get($cacheKey, 0) % $count;
        Cache::put($cacheKey, $cursor + 1, now()->addHours(6));

        return $base->slice($cursor)->concat($base->slice(0, $cursor))->values();
    }

    private function finalise(AiGatewayProvider $provider, ?AiGatewayModel $model, ?string $requestedModel): array
    {
        if ($model === null && $requestedModel) {
            $model = AiGatewayModel::query()
                ->where('provider_id', $provider->id)
                ->where('name', $requestedModel)
                ->first();
        }

        if ($model === null) {
            $model = $provider->models()->where('is_default', true)->first()
                ?? $provider->models()->where('is_active', true)->orderBy('id')->first();
        }

        $modelName = $model?->name ?? $provider->default_model
            ?? ($requestedModel ?: $provider->models()->value('name'));

        return [
            'provider' => $provider,
            'model' => $model,
            'modelName' => (string) $modelName,
        ];
    }
}
