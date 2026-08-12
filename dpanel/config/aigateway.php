<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Central configuration for the AI Gateway module. Providers are stored in
    | the database (ai_gateway_providers) so they can be managed from the UI,
    | while this file holds runtime defaults, network timeouts and fallback
    | behaviour used by the gateway service.
    |
    */

    // Default network timeout (seconds) for outbound provider requests.
    'timeout' => (int) env('AIGATEWAY_TIMEOUT', 90),

    // Maximum number of retries for transient provider failures.
    'max_retries' => (int) env('AIGATEWAY_MAX_RETRIES', 1),

    // When no routing rule matches, fall back to the first active provider.
    'fallback_to_first_active_provider' => (bool) env('AIGATEWAY_FALLBACK_TO_FIRST', true),

    // Cost unit currency used when writing usage records.
    'currency' => env('AIGATEWAY_CURRENCY', 'USD'),

    // Default temperature applied when a request/agent does not specify one.
    'default_temperature' => (float) env('AIGATEWAY_DEFAULT_TEMPERATURE', 0.3),

    // Whether request logs should store full request/response payloads.
    'log_payloads' => (bool) env('AIGATEWAY_LOG_PAYLOADS', true),

    // Default model catalogs keyed by driver. Used to seed the model list the
    // first time a provider of a given driver is created (and on "sync".
    'driver_default_models' => [
        'anthropic' => [
            ['name' => 'claude-sonnet-4-20250514', 'display_name' => 'Claude Sonnet 4', 'context_window' => 200000, 'max_output_tokens' => 64000, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 3.00, 'output_price' => 15.00],
            ['name' => 'claude-3-7-sonnet-20250219', 'display_name' => 'Claude 3.7 Sonnet', 'context_window' => 200000, 'max_output_tokens' => 64000, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 3.00, 'output_price' => 15.00],
            ['name' => 'claude-3-5-haiku-20241022', 'display_name' => 'Claude 3.5 Haiku', 'context_window' => 200000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0.80, 'output_price' => 4.00],
            ['name' => 'claude-opus-4-20250514', 'display_name' => 'Claude Opus 4', 'context_window' => 200000, 'max_output_tokens' => 32000, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 15.00, 'output_price' => 75.00],
        ],
        'openai' => [
            ['name' => 'gpt-4.1', 'display_name' => 'GPT-4.1', 'context_window' => 1047576, 'max_output_tokens' => 32768, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 2.00, 'output_price' => 8.00],
            ['name' => 'gpt-4o-mini', 'display_name' => 'GPT-4o mini', 'context_window' => 128000, 'max_output_tokens' => 16384, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 0.15, 'output_price' => 0.60],
            ['name' => 'gpt-4o', 'display_name' => 'GPT-4o', 'context_window' => 128000, 'max_output_tokens' => 16384, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 2.50, 'output_price' => 10.00],
        ],
        'openai_compatible' => [
            ['name' => 'gpt-4.1-mini', 'display_name' => 'GPT-4.1 mini', 'context_window' => 1047576, 'max_output_tokens' => 32768, 'capabilities' => ['chat', 'code'], 'input_price' => 0.40, 'output_price' => 1.60],
        ],
        'gemini' => [
            ['name' => 'gemini-2.5-flash', 'display_name' => 'Gemini 2.5 Flash', 'context_window' => 1048576, 'max_output_tokens' => 65536, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 0.30, 'output_price' => 2.50],
            ['name' => 'gemini-2.5-pro', 'display_name' => 'Gemini 2.5 Pro', 'context_window' => 1048576, 'max_output_tokens' => 65536, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 1.25, 'output_price' => 10.00],
        ],
    ],
];
