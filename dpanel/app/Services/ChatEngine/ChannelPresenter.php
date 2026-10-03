<?php

namespace App\Services\ChatEngine;

use App\Models\ChatChannel;

/**
 * The one array shape a channel ("app") is sent to the UI in — shared by
 * the Channels list and a business's Apps tab so both render the same card
 * and open the same edit form. Counts/last activity are only present when
 * the caller loaded them (withCount / withMax), otherwise they're null.
 */
class ChannelPresenter
{
    public static function present(ChatChannel $c): array
    {
        return [
            'id' => $c->id,
            'type' => $c->type,
            'name' => $c->name,
            'external_account_id' => $c->external_account_id,
            'system_prompt' => ($c->settings ?? [])['system_prompt'] ?? null,
            'has_bot_token' => (bool) $c->getBotToken(),
            'has_page_access_token' => (bool) $c->getPageAccessToken(),
            'has_whatsapp_access_token' => (bool) $c->getWhatsAppAccessToken(),
            'has_whatsapp_app_secret' => (bool) $c->getWhatsAppAppSecret(),
            'whatsapp_business_account_id' => $c->getWhatsAppBusinessAccountId(),
            'whatsapp_webhook_url' => $c->type === 'whatsapp' ? route('webhooks.chat.whatsapp', ['channel' => $c->id]) : null,
            'whatsapp_verify_token' => $c->type === 'whatsapp' ? $c->webhook_secret : null,
            'has_instagram_access_token' => (bool) $c->getInstagramAccessToken(),
            'has_instagram_app_secret' => (bool) $c->getInstagramAppSecret(),
            'instagram_webhook_url' => $c->type === 'instagram' ? route('webhooks.chat.instagram', ['channel' => $c->id]) : null,
            'instagram_verify_token' => $c->type === 'instagram' ? $c->webhook_secret : null,
            'has_slack_bot_token' => (bool) $c->getSlackBotToken(),
            'has_slack_signing_secret' => (bool) $c->getSlackSigningSecret(),
            'slack_webhook_url' => $c->type === 'slack' ? route('webhooks.chat.slack', ['channel' => $c->id]) : null,
            'widget_script_url' => $c->type === 'website' ? url('/widget/chat.js') : null,
            'is_active' => $c->is_active,
            'auto_reply_enabled' => $c->isAutoReplyEnabled(),
            'internal_access' => $c->isInternal(),
            'business' => $c->business ? ['id' => $c->business->id, 'name' => $c->business->name] : null,
            'contacts_count' => $c->contacts_count,
            'conversations_count' => $c->conversations_count,
            'last_message_at' => $c->conversations_max_last_message_at,
            'created_at' => $c->created_at?->toDateTimeString(),
            'owner' => $c->createdBy ? ['id' => $c->createdBy->id, 'name' => $c->createdBy->name, 'email' => $c->createdBy->email] : null,
        ];
    }
}
