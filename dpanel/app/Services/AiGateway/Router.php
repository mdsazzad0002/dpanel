<?php

namespace App\Services\AiGateway;

use App\Models\AiGatewayAgent;
use App\Models\AiGatewayModel;
use App\Models\AiGatewayProvider;
use App\Models\AiGatewayRoutingRule;
use Illuminate\Support\Str;

/**
 * Resolves which provider + model should serve a given gateway request based
 * on the configured routing rules (evaluated by priority), falling back to
 * the highest-weight active provider when nothing matches.
 */
class Router
{
    /**
     * @param  array{model?: string, agent?: AiGatewayAgent|null, taskType?: string|null, taskTitle?: string|null}  $context
     * @return array{provider: AiGatewayProvider, model: AiGatewayModel|null, modelName: string}
     */
    public function resolve(array $context = []): array
    {
        $context = array_merge(['model' => null, 'agent' => null, 'taskType' => null, 'taskTitle' => null], $context);

        $query = AiGatewayRoutingRule::query()
            ->where('is_active', true)
            ->with(['provider', 'model'])
            ->orderByDesc('priority')
            ->orderBy('id');

        foreach ($query->get() as $rule) {
            if (! $this->matches($rule, $context)) {
                continue;
            }

            $provider = $rule->provider ?: $rule->model?->provider;

            if ($provider && $provider->is_active) {
                return $this->finalise($provider, $rule->model, $context['model']);
            }
        }

        // Fallback: a specific model was requested — route to its owner provider.
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

        // Fallback: prefer the highest-weight active provider.
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

    private function matches(AiGatewayRoutingRule $rule, array $context): bool
    {
        return match ($rule->match_type) {
            'always' => true,
            'model' => $this->valueMatches($rule->match_value, $context['model']),
            'agent' => $this->valueMatches($rule->match_value, $context['agent']?->slug),
            'task' => $this->valueMatches($rule->match_value, $context['taskType'])
                || $this->valueMatches($rule->match_value, $context['taskTitle']),
            default => false,
        };
    }

    private function valueMatches(?string $pattern, ?string $value): bool
    {
        if ($pattern === null || $pattern === '' || $value === null || $value === '') {
            return false;
        }

        $value = strtolower(trim($value));

        return $pattern === '*' || Str::is(strtolower($pattern), $value);
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
