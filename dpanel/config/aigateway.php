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

    // Image generation takes longer than a chat reply.
    'image_timeout' => (int) env('AIGATEWAY_IMAGE_TIMEOUT', 180),

    // So does transcribing a long voice recording.
    'transcription_timeout' => (int) env('AIGATEWAY_TRANSCRIPTION_TIMEOUT', 180),
    // drust's whisper.cpp (media/transcribe) as the voice-to-text gateway:
    // "first" = before the transcription models, "fallback" = only when they fail or none exist, "off"
    'local_transcription' => env('AIGATEWAY_LOCAL_TRANSCRIPTION', 'first'),

    // whisper.cpp models the Voice to Text page offers to download (drust fetches them
    // from huggingface.co/ggerganov/whisper.cpp); sizes in MB, speed relative on CPU
    'whisper_catalog' => [
        ['file' => 'ggml-tiny-q5_1.bin', 'label' => 'Tiny', 'size_mb' => 31, 'note' => 'Fastest, English only really'],
        ['file' => 'ggml-base-q5_1.bin', 'label' => 'Base', 'size_mb' => 57, 'note' => 'Fast; weak for Bangla'],
        ['file' => 'ggml-small-q5_1.bin', 'label' => 'Small', 'size_mb' => 181, 'note' => 'Understands Bangla, often writes it in another script'],
        ['file' => 'ggml-medium-q5_0.bin', 'label' => 'Medium', 'size_mb' => 514, 'note' => 'Better, about 3× slower than Small'],
        ['file' => 'ggml-large-v3-turbo-q5_0.bin', 'label' => 'Large v3 Turbo', 'size_mb' => 547, 'note' => 'Best for Bangla on CPU (recommended)'],
        ['file' => 'ggml-large-v3-turbo-q8_0.bin', 'label' => 'Large v3 Turbo (q8)', 'size_mb' => 834, 'note' => 'Slightly more accurate, bigger'],
        ['file' => 'ggml-large-v3-q5_0.bin', 'label' => 'Large v3', 'size_mb' => 1080, 'note' => 'Most accurate, slowest'],
    ],

    // Maximum number of retries for transient provider failures.
    'max_retries' => (int) env('AIGATEWAY_MAX_RETRIES', 1),

    // When no routing rule matches, fall back to the first active provider.
    'fallback_to_first_active_provider' => (bool) env('AIGATEWAY_FALLBACK_TO_FIRST', true),

    // Cost unit currency used when writing usage records.
    'currency' => env('AIGATEWAY_CURRENCY', 'USD'),

    // Default temperature applied when a request/agent does not specify one.
    'default_temperature' => (float) env('AIGATEWAY_DEFAULT_TEMPERATURE', 0.3),

    // Default max output tokens applied when a request doesn't specify one.
    // Keeps unbounded completions from exhausting a provider's free/paid
    // credit balance on models with very large default output limits.
    'default_max_tokens' => (int) env('AIGATEWAY_DEFAULT_MAX_TOKENS', 2048),

    // Whether request logs should store full request/response payloads.
    'log_payloads' => (bool) env('AIGATEWAY_LOG_PAYLOADS', true),

    // Default model catalogs keyed by driver. Used to seed the model list the
    // first time a provider of a given driver is created (and on "sync".
    'driver_default_models' => [
        'anthropic' => [
            ['name' => 'claude-sonnet-4-20250514', 'display_name' => 'Claude Sonnet 4', 'context_window' => 200000, 'max_output_tokens' => 64000, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 3.00, 'output_price' => 15.00],
            ['name' => 'claude-opus-4-20250514', 'display_name' => 'Claude Opus 4', 'context_window' => 200000, 'max_output_tokens' => 32000, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 15.00, 'output_price' => 75.00],
        ],
        'openai' => [
            ['name' => 'gpt-4.1', 'display_name' => 'GPT-4.1', 'context_window' => 1047576, 'max_output_tokens' => 32768, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 2.00, 'output_price' => 8.00],
            ['name' => 'gpt-4o-mini', 'display_name' => 'GPT-4o mini', 'context_window' => 128000, 'max_output_tokens' => 16384, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 0.15, 'output_price' => 0.60],
            ['name' => 'gpt-4o', 'display_name' => 'GPT-4o', 'context_window' => 128000, 'max_output_tokens' => 16384, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 2.50, 'output_price' => 10.00],
            // Image model: "image_generation" without "chat" keeps it out of chat routing.
            ['name' => 'gpt-image-1', 'display_name' => 'GPT Image 1', 'context_window' => 0, 'max_output_tokens' => 0, 'capabilities' => ['image_generation'], 'input_price' => 0, 'output_price' => 0],
            // Speech to text: "transcription" without "chat", like the image model.
            ['name' => 'gpt-4o-mini-transcribe', 'display_name' => 'GPT-4o mini Transcribe', 'context_window' => 0, 'max_output_tokens' => 0, 'capabilities' => ['transcription'], 'input_price' => 0, 'output_price' => 0],
        ],
        // Free-tier models on OpenRouter (":free" suffix). Subject to daily
        // rate limits set by OpenRouter, not by this gateway.
        'openrouter' => [
            ['name' => 'x-ai/grok-4-fast:free', 'display_name' => 'Grok 4 Fast (free)', 'context_window' => 2000000, 'max_output_tokens' => 32768, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'deepseek/deepseek-chat-v3.1:free', 'display_name' => 'DeepSeek Chat v3.1 (free)', 'context_window' => 163840, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'meta-llama/llama-3.3-70b-instruct:free', 'display_name' => 'Llama 3.3 70B (free)', 'context_window' => 131072, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'qwen/qwen3-235b-a22b:free', 'display_name' => 'Qwen3 235B (free)', 'context_window' => 131072, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'openai/gpt-oss-20b:free', 'display_name' => 'GPT-OSS 20B (free)', 'context_window' => 131072, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
        ],
        'gemini' => [
            ['name' => 'gemini-2.5-flash', 'display_name' => 'Gemini 2.5 Flash', 'context_window' => 1048576, 'max_output_tokens' => 65536, 'capabilities' => ['chat', 'vision', 'code', 'transcription'], 'input_price' => 0.30, 'output_price' => 2.50],
            ['name' => 'gemini-2.5-pro', 'display_name' => 'Gemini 2.5 Pro', 'context_window' => 1048576, 'max_output_tokens' => 65536, 'capabilities' => ['chat', 'vision', 'code'], 'input_price' => 1.25, 'output_price' => 10.00],
            ['name' => 'gemini-2.5-flash-image', 'display_name' => 'Gemini 2.5 Flash Image', 'context_window' => 32768, 'max_output_tokens' => 32768, 'capabilities' => ['image_generation'], 'input_price' => 0, 'output_price' => 0],
        ],
        // Groq's free tier is generous and very fast (LPU inference).
        'groq' => [
            ['name' => 'llama-3.3-70b-versatile', 'display_name' => 'Llama 3.3 70B (free)', 'context_window' => 128000, 'max_output_tokens' => 32768, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'llama-3.1-8b-instant', 'display_name' => 'Llama 3.1 8B Instant (free)', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'whisper-large-v3-turbo', 'display_name' => 'Whisper Large v3 Turbo (free)', 'context_window' => 0, 'max_output_tokens' => 0, 'capabilities' => ['transcription'], 'input_price' => 0, 'output_price' => 0],
        ],
        'deepseek' => [
            ['name' => 'deepseek-chat', 'display_name' => 'DeepSeek Chat (V3)', 'context_window' => 64000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0.27, 'output_price' => 1.10],
            ['name' => 'deepseek-reasoner', 'display_name' => 'DeepSeek Reasoner (R1)', 'context_window' => 64000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0.55, 'output_price' => 2.19],
        ],
        // Mistral's "la Plateforme" free tier covers these models.
        'mistral' => [
            ['name' => 'mistral-small-latest', 'display_name' => 'Mistral Small (free tier)', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'open-mistral-nemo', 'display_name' => 'Mistral Nemo (free tier)', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'mistral-large-latest', 'display_name' => 'Mistral Large', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 2.00, 'output_price' => 6.00],
        ],
        // Cerebras offers a free tier with very high token throughput.
        'cerebras' => [
            ['name' => 'llama-3.3-70b', 'display_name' => 'Llama 3.3 70B (free)', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'llama3.1-8b', 'display_name' => 'Llama 3.1 8B (free)', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'qwen-3-32b', 'display_name' => 'Qwen3 32B (free)', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
        ],
        // Kilo's stable auto routes choose an upstream model based on the
        // requested quality/cost tier. Prices are resolved by Kilo at request
        // time, so the local estimates intentionally remain zero.
        'kilo' => [
            ['name' => 'kilo-auto/free', 'display_name' => 'Kilo Auto Free', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'kilo-auto/efficient', 'display_name' => 'Kilo Auto Efficient', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'kilo-auto/balanced', 'display_name' => 'Kilo Auto Balanced', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
            ['name' => 'kilo-auto/frontier', 'display_name' => 'Kilo Auto Frontier', 'context_window' => 128000, 'max_output_tokens' => 8192, 'capabilities' => ['chat', 'code'], 'input_price' => 0, 'output_price' => 0],
        ],
    ],

    /*
    | Local LLM runner (drust-llm): GGUF models served on this server. The
    | installer writes the key here when it installs the runner.
    */
    'local_llm' => [
        'url' => rtrim((string) env('AIGATEWAY_LOCAL_LLM_URL', 'http://127.0.0.1:9510'), '/'),
        'key' => (string) env('AIGATEWAY_LOCAL_LLM_KEY', ''),
        // Loading a model on first use can take a minute.
        'timeout' => (int) env('AIGATEWAY_LOCAL_LLM_TIMEOUT', 120),
        // One chat turn on a local model: loading it, then reading the
        // prompt on the CPU, can take minutes.
        'chat_timeout' => (int) env('AIGATEWAY_LOCAL_LLM_CHAT_TIMEOUT', 600),
        // Turned on and off from the Local Models page, through drust.
        'services' => [
            'drust-llm' => [
                'label' => 'drust-llm',
                'unit' => env('AIGATEWAY_LOCAL_LLM_SERVICE', 'drust-llm'),
            ],
        ],
        // Groups for the catalog, in display order.
        'catalog_categories' => [
            'general' => ['label' => 'General chat', 'icon' => 'bi-chat-dots', 'note' => 'Everyday questions, writing and support replies.'],
            'multilingual' => ['label' => 'Bengali & multilingual', 'icon' => 'bi-translate', 'note' => 'Best at languages other than English, Bengali included.'],
            'coding' => ['label' => 'Coding', 'icon' => 'bi-code-slash', 'note' => 'Writing, explaining and fixing code.'],
            'reasoning' => ['label' => 'Reasoning', 'icon' => 'bi-lightbulb', 'note' => 'Think step by step first: maths, logic, planning. Slower replies.'],
            'tiny' => ['label' => 'Tiny & fast', 'icon' => 'bi-lightning-charge', 'note' => 'Run on 1-2 GB RAM: tagging, short replies, simple rewrites.'],
        ],
        // One-click downloads on the Local Models page, grouped by category.
        // Sizes are the file sizes; a model needs about that much free RAM
        // to load. All are Q4_K_M quantizations that run on a CPU.
        'catalog' => [
            ['id' => 'qwen2.5-1.5b-instruct-q4_k_m', 'name' => 'Qwen2.5 1.5B Instruct', 'category' => 'general', 'size_bytes' => 1117320736, 'note' => 'Good balance for 4 GB servers.', 'url' => 'https://huggingface.co/Qwen/Qwen2.5-1.5B-Instruct-GGUF/resolve/main/qwen2.5-1.5b-instruct-q4_k_m.gguf'],
            ['id' => 'ibm-granite_granite-3.3-2b-instruct-Q4_K_M', 'name' => 'Granite 3.3 2B Instruct', 'category' => 'general', 'size_bytes' => 1545303616, 'note' => 'IBM; business writing, summaries and RAG answers.', 'url' => 'https://huggingface.co/bartowski/ibm-granite_granite-3.3-2b-instruct-GGUF/resolve/main/ibm-granite_granite-3.3-2b-instruct-Q4_K_M.gguf'],
            ['id' => 'SmolLM3-3B-Q4_K_M', 'name' => 'SmolLM3 3B', 'category' => 'general', 'size_bytes' => 1915306528, 'note' => 'Hugging Face; long context, can think or answer directly.', 'url' => 'https://huggingface.co/unsloth/SmolLM3-3B-GGUF/resolve/main/SmolLM3-3B-Q4_K_M.gguf'],
            ['id' => 'Llama-3.2-3B-Instruct-Q4_K_M', 'name' => 'Llama 3.2 3B Instruct', 'category' => 'general', 'size_bytes' => 2019377696, 'note' => 'Meta; general chat.', 'url' => 'https://huggingface.co/bartowski/Llama-3.2-3B-Instruct-GGUF/resolve/main/Llama-3.2-3B-Instruct-Q4_K_M.gguf'],
            ['id' => 'Phi-4-mini-instruct-Q4_K_M', 'name' => 'Phi-4 Mini Instruct', 'category' => 'general', 'size_bytes' => 2491874272, 'note' => 'Microsoft 3.8B; good at instructions, maths and structured output.', 'url' => 'https://huggingface.co/unsloth/Phi-4-mini-instruct-GGUF/resolve/main/Phi-4-mini-instruct-Q4_K_M.gguf'],
            ['id' => 'Qwen2.5-7B-Instruct-Q4_K_M', 'name' => 'Qwen2.5 7B Instruct', 'category' => 'general', 'size_bytes' => 4683074240, 'note' => 'Much better answers; needs 8 GB free RAM and is slow on CPU.', 'url' => 'https://huggingface.co/bartowski/Qwen2.5-7B-Instruct-GGUF/resolve/main/Qwen2.5-7B-Instruct-Q4_K_M.gguf'],
            ['id' => 'Mistral-7B-Instruct-v0.3-Q4_K_M', 'name' => 'Mistral 7B Instruct v0.3', 'category' => 'general', 'size_bytes' => 4372812000, 'note' => 'Mistral; reliable all-rounder, needs 8 GB RAM.', 'url' => 'https://huggingface.co/bartowski/Mistral-7B-Instruct-v0.3-GGUF/resolve/main/Mistral-7B-Instruct-v0.3-Q4_K_M.gguf'],
            ['id' => 'Meta-Llama-3.1-8B-Instruct-Q4_K_M', 'name' => 'Llama 3.1 8B Instruct', 'category' => 'general', 'size_bytes' => 4920739232, 'note' => 'Meta; needs 8 GB free RAM and is slow on CPU.', 'url' => 'https://huggingface.co/bartowski/Meta-Llama-3.1-8B-Instruct-GGUF/resolve/main/Meta-Llama-3.1-8B-Instruct-Q4_K_M.gguf'],
            ['id' => 'Qwen3-30B-A3B-Q4_K_M', 'name' => 'Qwen3 30B-A3B (MoE)', 'category' => 'general', 'size_bytes' => 18556686912, 'note' => 'Only 3B active per token, so fast for its quality; needs 24 GB RAM.', 'url' => 'https://huggingface.co/unsloth/Qwen3-30B-A3B-GGUF/resolve/main/Qwen3-30B-A3B-Q4_K_M.gguf'],
            ['id' => 'qwen2.5-3b-instruct-q4_k_m', 'name' => 'Qwen2.5 3B Instruct', 'category' => 'multilingual', 'size_bytes' => 2104932768, 'recommended' => true, 'note' => 'Recommended for 8 GB servers; solid Bengali for its size.', 'url' => 'https://huggingface.co/Qwen/Qwen2.5-3B-Instruct-GGUF/resolve/main/qwen2.5-3b-instruct-q4_k_m.gguf'],
            ['id' => 'qwen2.5-coder-0.5b-instruct-q4_k_m', 'name' => 'Qwen2.5 Coder 0.5B Instruct', 'category' => 'coding', 'size_bytes' => 491400064, 'note' => 'Tiny; quick completions and small snippets.', 'url' => 'https://huggingface.co/Qwen/Qwen2.5-Coder-0.5B-Instruct-GGUF/resolve/main/qwen2.5-coder-0.5b-instruct-q4_k_m.gguf'],
            ['id' => 'qwen2.5-coder-1.5b-instruct-q4_k_m', 'name' => 'Qwen2.5 Coder 1.5B Instruct', 'category' => 'coding', 'size_bytes' => 1117320768, 'note' => 'Tuned for code; fits 4 GB servers.', 'url' => 'https://huggingface.co/Qwen/Qwen2.5-Coder-1.5B-Instruct-GGUF/resolve/main/qwen2.5-coder-1.5b-instruct-q4_k_m.gguf'],
            ['id' => 'qwen2.5-coder-3b-instruct-q4_k_m', 'name' => 'Qwen2.5 Coder 3B Instruct', 'category' => 'coding', 'size_bytes' => 2104932800, 'recommended' => true, 'note' => 'Recommended for code on 8 GB servers.', 'url' => 'https://huggingface.co/Qwen/Qwen2.5-Coder-3B-Instruct-GGUF/resolve/main/qwen2.5-coder-3b-instruct-q4_k_m.gguf'],
            ['id' => 'Qwen2.5-Coder-7B-Instruct-Q4_K_M', 'name' => 'Qwen2.5 Coder 7B Instruct', 'category' => 'coding', 'size_bytes' => 4683074336, 'note' => 'Strong code model; needs 8 GB free RAM.', 'url' => 'https://huggingface.co/bartowski/Qwen2.5-Coder-7B-Instruct-GGUF/resolve/main/Qwen2.5-Coder-7B-Instruct-Q4_K_M.gguf'],
            ['id' => 'Qwen3-1.7B-Q4_K_M', 'name' => 'Qwen3 1.7B', 'category' => 'reasoning', 'size_bytes' => 1107409472, 'note' => 'Thinks before answering; add /no_think to a message to skip it.', 'url' => 'https://huggingface.co/unsloth/Qwen3-1.7B-GGUF/resolve/main/Qwen3-1.7B-Q4_K_M.gguf'],
            ['id' => 'Qwen3-4B-Q4_K_M', 'name' => 'Qwen3 4B', 'category' => 'reasoning', 'size_bytes' => 2497281312, 'recommended' => true, 'note' => 'Best reasoning for 8 GB servers; also good general chat.', 'url' => 'https://huggingface.co/unsloth/Qwen3-4B-GGUF/resolve/main/Qwen3-4B-Q4_K_M.gguf'],
            ['id' => 'Qwen3-8B-Q4_K_M', 'name' => 'Qwen3 8B', 'category' => 'reasoning', 'size_bytes' => 5027784512, 'note' => 'Thinking model; needs 8 GB free RAM.', 'url' => 'https://huggingface.co/unsloth/Qwen3-8B-GGUF/resolve/main/Qwen3-8B-Q4_K_M.gguf'],
            ['id' => 'Qwen3-0.6B-Q4_K_M', 'name' => 'Qwen3 0.6B', 'category' => 'tiny', 'size_bytes' => 396705472, 'note' => 'Tiny Qwen3; can think, runs on 1-2 GB RAM.', 'url' => 'https://huggingface.co/unsloth/Qwen3-0.6B-GGUF/resolve/main/Qwen3-0.6B-Q4_K_M.gguf'],
            ['id' => 'qwen2.5-0.5b-instruct-q4_k_m', 'name' => 'Qwen2.5 0.5B Instruct', 'category' => 'tiny', 'size_bytes' => 491400032, 'note' => 'Tiny and fast; fine for short, simple answers.', 'url' => 'https://huggingface.co/Qwen/Qwen2.5-0.5B-Instruct-GGUF/resolve/main/qwen2.5-0.5b-instruct-q4_k_m.gguf'],
            ['id' => 'Llama-3.2-1B-Instruct-Q4_K_M', 'name' => 'Llama 3.2 1B Instruct', 'category' => 'tiny', 'size_bytes' => 807694464, 'note' => 'Meta; summaries and rewrites on small servers.', 'url' => 'https://huggingface.co/bartowski/Llama-3.2-1B-Instruct-GGUF/resolve/main/Llama-3.2-1B-Instruct-Q4_K_M.gguf'],
        ],
    ],
];
