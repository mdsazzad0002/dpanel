<?php

namespace App\Services\AiGateway;

/**
 * Shared helpers for turning a provider's raw /models entries into the
 * picker's catalog: decide what each model can do, and drop models the
 * provider has deprecated so admins never pick one that's about to vanish.
 */
class ModelCatalog
{
    /**
     * Whether the raw entry carries one of the deprecation markers providers
     * use: Groq's `active: false`, OpenRouter/Kilo's `expiration_date`,
     * Mistral's `deprecation` date, or a plain `deprecated` flag.
     */
    public static function flaggedDeprecated(array $raw): bool
    {
        if (($raw['active'] ?? true) === false || ! empty($raw['deprecated'])) {
            return true;
        }

        foreach (['expiration_date', 'deprecation', 'deprecation_date', 'deprecated_at'] as $key) {
            if (! empty($raw[$key])) {
                return true;
            }
        }

        return isset($raw['status']) && in_array(strtolower((string) $raw['status']), ['deprecated', 'retired', 'inactive'], true);
    }

    /**
     * Whether a model description announces its own deprecation (Gemini puts
     * this in prose rather than in a field).
     */
    public static function describedAsDeprecated(?string $description): bool
    {
        return $description !== null && preg_match('/\b(deprecated|discontinued|will be (shut ?down|retired|removed))\b/i', $description) === 1;
    }

    /**
     * Keep only known capability tags, in a stable order.
     *
     * @param  array<int, string>  $capabilities
     * @return array<int, string>
     */
    public static function normalise(array $capabilities): array
    {
        return array_values(array_intersect(\App\Models\AiGatewayModel::CAPABILITIES, $capabilities));
    }

    /**
     * "Good at code" is a judgement call no provider reports. Treat dedicated
     * code models, and general chat models that can call tools, as such.
     *
     * @param  array<int, string>  $capabilities
     * @return array<int, string>
     */
    public static function withCodeHint(string $model, array $capabilities): array
    {
        if (in_array('chat', $capabilities, true)
            && (in_array('tools', $capabilities, true) || preg_match('/cod(e|er|estral)|devstral/i', $model))) {
            $capabilities[] = 'code';
        }

        return self::normalise($capabilities);
    }
}
