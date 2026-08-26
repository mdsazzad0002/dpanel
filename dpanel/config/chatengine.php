<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Chat Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Runtime defaults for the multi-channel chat engine. Channels themselves
    | (Telegram bots, Facebook pages, WhatsApp numbers) live in the database
    | (chat_channels) so they can be managed from the UI.
    |
    */

    // Fallback system prompt used when a channel doesn't define its own.
    'default_system_prompt' => env(
        'CHATENGINE_DEFAULT_SYSTEM_PROMPT',
        'You are a helpful, concise assistant replying to a customer message.'
    ),

    // How many prior messages (inbound + outbound) to send to the AI as
    // conversation context.
    'context_message_limit' => (int) env('CHATENGINE_CONTEXT_MESSAGE_LIMIT', 20),

    // Queue used for inbound-message processing and scheduled sends.
    'queue' => env('CHATENGINE_QUEUE', 'chat'),

    'telegram' => [
        'api_base_url' => env('CHATENGINE_TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    ],

    'whatsapp' => [
        'api_base_url' => env('CHATENGINE_WHATSAPP_API_BASE_URL', 'https://graph.facebook.com/'.env('CHATENGINE_FACEBOOK_GRAPH_VERSION', 'v21.0')),
    ],

    'instagram' => [
        'graph_version' => env('CHATENGINE_INSTAGRAM_GRAPH_VERSION', 'v26.0'),
        'api_base_url' => env('CHATENGINE_INSTAGRAM_API_BASE_URL', 'https://graph.instagram.com/'.env('CHATENGINE_INSTAGRAM_GRAPH_VERSION', 'v26.0')),
    ],

    'slack' => [
        'api_base_url' => env('CHATENGINE_SLACK_API_BASE_URL', 'https://slack.com/api'),
    ],

    // Legacy single-app Facebook config, kept only for the shared fallback
    // webhook (see FacebookChatWebhookController) — per-app registration via
    // "Facebook Apps" in the panel is the recommended path now.
    'facebook' => [
        // Graph API version — bump this (or CHATENGINE_FACEBOOK_GRAPH_VERSION)
        // as Meta deprecates older versions; both the Graph API base URL and
        // the OAuth login dialog derive from it, so one change covers both.
        'graph_version' => env('CHATENGINE_FACEBOOK_GRAPH_VERSION', 'v21.0'),
        'api_base_url' => env('CHATENGINE_FACEBOOK_API_BASE_URL', 'https://graph.facebook.com/'.env('CHATENGINE_FACEBOOK_GRAPH_VERSION', 'v21.0')),
        'app_secret' => env('CHATENGINE_FACEBOOK_APP_SECRET'),
        'verify_token' => env('CHATENGINE_FACEBOOK_VERIFY_TOKEN'),

        // Permissions requested when a user clicks "Connect via Facebook".
        // Each must be added under the app's App Review → Permissions and
        // Features in the Meta dashboard before Facebook will grant it (even
        // in Development mode, for the app's own admins/developers/testers).
        //   pages_show_list          — list the pages the user manages
        //   pages_manage_metadata    — subscribe the page to this app's webhook
        //   pages_read_engagement    — receive/read Page feed comments
        //   pages_manage_engagement  — publish replies to Page comments
        //   pages_manage_posts       — publish posts to the Page feed
        // Keep the default request limited to permissions the current
        // Messenger integration actually needs. Extra permissions make Meta
        // reject the whole login when an app has not received Advanced Access
        // for an unrelated feature.
        'scopes' => array_filter(explode(',', env(
            'CHATENGINE_FACEBOOK_SCOPES',
            'pages_show_list,pages_manage_metadata,pages_read_engagement,pages_manage_engagement,pages_manage_posts'
        ))),

        // Only permissions returned by /me/permissions belong here.
        // `pages_messaging` is a Messenger Platform/App Review capability,
        // not a valid Facebook Login dialog scope for affected Meta apps.
        'required_scopes' => [
            'pages_show_list',
            'pages_manage_metadata',
            'pages_read_engagement',
            'pages_manage_engagement',
            'pages_manage_posts',
        ],
    ],
];
