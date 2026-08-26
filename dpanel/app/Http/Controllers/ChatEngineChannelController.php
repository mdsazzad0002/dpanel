<?php

namespace App\Http\Controllers;

use App\Models\ChatChannel;
use App\Models\User;
use App\Services\ChatEngine\ChatEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ChatEngineChannelController extends Controller
{
    public function __construct(private readonly ChatEngineService $chatEngine)
    {
    }

    public function index(Request $request): Response
    {
        $channels = ChatChannel::query()
            ->visibleTo($request->user())
            ->with('createdBy:id,name,email')
            ->withCount(['contacts', 'conversations'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ChatChannel $c): array => [
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
                'is_active' => $c->is_active,
                'auto_reply_enabled' => $c->isAutoReplyEnabled(),
                'contacts_count' => $c->contacts_count,
                'conversations_count' => $c->conversations_count,
                'created_at' => $c->created_at?->toDateTimeString(),
                'owner' => $c->createdBy ? ['id' => $c->createdBy->id, 'name' => $c->createdBy->name, 'email' => $c->createdBy->email] : null,
            ]);

        return Inertia::render('ChatEngine/Channels/Index', [
            'channels' => $channels,
            'facebookWebhookUrl' => route('webhooks.chat.facebook'),
            'owners' => $channels->pluck('owner')->filter()->unique('id')->values(),
            'ownershipOptions' => $this->ownershipOptions($request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('ChatEngine/Channels/Create', [
            'facebookWebhookUrl' => route('webhooks.chat.facebook'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['telegram', 'facebook', 'whatsapp', 'instagram', 'slack'])],
            'name' => ['required', 'string', 'max:255'],
            'bot_token' => ['required_if:type,telegram', 'nullable', 'string', 'max:255'],
            'page_id' => ['required_if:type,facebook', 'nullable', 'string', 'max:255'],
            'page_access_token' => ['required_if:type,facebook', 'nullable', 'string', 'max:2000'],
            'whatsapp_phone_number_id' => ['required_if:type,whatsapp', 'nullable', 'string', 'max:255'],
            'whatsapp_business_account_id' => ['required_if:type,whatsapp', 'nullable', 'string', 'max:255'],
            'whatsapp_access_token' => ['required_if:type,whatsapp', 'nullable', 'string', 'max:4000'],
            'whatsapp_app_secret' => ['required_if:type,whatsapp', 'nullable', 'string', 'max:255'],
            'instagram_account_id' => ['required_if:type,instagram', 'nullable', 'string', 'max:255'],
            'instagram_access_token' => ['required_if:type,instagram', 'nullable', 'string', 'max:4000'],
            'instagram_app_secret' => ['required_if:type,instagram', 'nullable', 'string', 'max:255'],
            'slack_workspace_id' => ['required_if:type,slack', 'nullable', 'string', 'max:255'],
            'slack_bot_token' => ['required_if:type,slack', 'nullable', 'string', 'max:2000'],
            'slack_signing_secret' => ['required_if:type,slack', 'nullable', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:4000'],
            'auto_reply_enabled' => ['nullable', 'boolean'],
        ]);

        $type = $validated['type'];

        $channel = ChatChannel::create([
            'type' => $type,
            'name' => $validated['name'],
            'external_account_id' => match ($type) {
                'facebook' => $validated['page_id'],
                'whatsapp' => $validated['whatsapp_phone_number_id'],
                'instagram' => $validated['instagram_account_id'],
                'slack' => $validated['slack_workspace_id'],
                default => null,
            },
            'credentials' => match ($type) {
                'facebook' => ['page_access_token' => $validated['page_access_token']],
                'whatsapp' => [
                    'business_account_id' => $validated['whatsapp_business_account_id'],
                    'access_token' => $validated['whatsapp_access_token'],
                    'app_secret' => $validated['whatsapp_app_secret'],
                ],
                'instagram' => [
                    'access_token' => $validated['instagram_access_token'],
                    'app_secret' => $validated['instagram_app_secret'],
                ],
                'slack' => [
                    'bot_token' => $validated['slack_bot_token'],
                    'signing_secret' => $validated['slack_signing_secret'],
                ],
                default => ['bot_token' => $validated['bot_token']],
            },
            'webhook_secret' => Str::random(40),
            'settings' => [
                'system_prompt' => $validated['system_prompt'] ?? null,
                'auto_reply_enabled' => (bool) ($validated['auto_reply_enabled'] ?? true),
            ],
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        try {
            $this->reconnectWebhook($channel);
        } catch (\Throwable $e) {
            return redirect()
                ->route('chat-engine.channels.index')
                ->with('error', 'Channel saved, but registering the webhook failed: '.$e->getMessage());
        }

        return redirect()
            ->route('chat-engine.channels.index')
            ->with('success', ucfirst($type).' channel "'.$channel->name.'" connected.');
    }

    public function edit($token, ChatChannel $channel): Response
    {
        $this->authorizeChannel(request(), $channel);
        return Inertia::render('ChatEngine/Channels/Edit', [
            'channel' => [
                'id' => $channel->id,
                'type' => $channel->type,
                'name' => $channel->name,
                'external_account_id' => $channel->external_account_id,
                'system_prompt' => ($channel->settings ?? [])['system_prompt'] ?? null,
                'auto_reply_enabled' => $channel->isAutoReplyEnabled(),
                'is_active' => $channel->is_active,
                'has_bot_token' => (bool) $channel->getBotToken(),
                'has_page_access_token' => (bool) $channel->getPageAccessToken(),
                'has_whatsapp_access_token' => (bool) $channel->getWhatsAppAccessToken(),
                'has_whatsapp_app_secret' => (bool) $channel->getWhatsAppAppSecret(),
                'whatsapp_business_account_id' => $channel->getWhatsAppBusinessAccountId(),
                'whatsapp_webhook_url' => $channel->type === 'whatsapp' ? route('webhooks.chat.whatsapp', ['channel' => $channel->id]) : null,
                'whatsapp_verify_token' => $channel->type === 'whatsapp' ? $channel->webhook_secret : null,
                'has_instagram_access_token' => (bool) $channel->getInstagramAccessToken(),
                'has_instagram_app_secret' => (bool) $channel->getInstagramAppSecret(),
                'instagram_webhook_url' => $channel->type === 'instagram' ? route('webhooks.chat.instagram', ['channel' => $channel->id]) : null,
                'instagram_verify_token' => $channel->type === 'instagram' ? $channel->webhook_secret : null,
                'has_slack_bot_token' => (bool) $channel->getSlackBotToken(),
                'has_slack_signing_secret' => (bool) $channel->getSlackSigningSecret(),
                'slack_webhook_url' => $channel->type === 'slack' ? route('webhooks.chat.slack', ['channel' => $channel->id]) : null,
            ],
            'facebookWebhookUrl' => route('webhooks.chat.facebook'),
        ]);
    }

    public function update(Request $request, $token, ChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'bot_token' => ['nullable', 'string', 'max:255'],
            'page_id' => ['nullable', 'string', 'max:255'],
            'page_access_token' => ['nullable', 'string', 'max:2000'],
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:255'],
            'whatsapp_business_account_id' => ['nullable', 'string', 'max:255'],
            'whatsapp_access_token' => ['nullable', 'string', 'max:4000'],
            'whatsapp_app_secret' => ['nullable', 'string', 'max:255'],
            'instagram_account_id' => ['nullable', 'string', 'max:255'],
            'instagram_access_token' => ['nullable', 'string', 'max:4000'],
            'instagram_app_secret' => ['nullable', 'string', 'max:255'],
            'slack_workspace_id' => ['nullable', 'string', 'max:255'],
            'slack_bot_token' => ['nullable', 'string', 'max:2000'],
            'slack_signing_secret' => ['nullable', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:4000'],
            'auto_reply_enabled' => ['nullable', 'boolean'],
        ]);

        $channel->update([
            'name' => $validated['name'],
            'external_account_id' => match ($channel->type) {
                'facebook' => $validated['page_id'] ?: $channel->external_account_id,
                'whatsapp' => $validated['whatsapp_phone_number_id'] ?: $channel->external_account_id,
                'instagram' => $validated['instagram_account_id'] ?: $channel->external_account_id,
                'slack' => $validated['slack_workspace_id'] ?: $channel->external_account_id,
                default => $channel->external_account_id,
            },
            'settings' => [
                'system_prompt' => $validated['system_prompt'] ?? null,
                'auto_reply_enabled' => (bool) ($validated['auto_reply_enabled'] ?? true),
            ],
        ]);

        $credentialsChanged = false;

        if ($channel->type === 'telegram' && ! empty($validated['bot_token'])) {
            $channel->update(['credentials' => ['bot_token' => $validated['bot_token']]]);
            $credentialsChanged = true;
        }

        if ($channel->type === 'facebook' && ! empty($validated['page_access_token'])) {
            $channel->update(['credentials' => ['page_access_token' => $validated['page_access_token']]]);
            $credentialsChanged = true;
        }

        if ($channel->type === 'whatsapp') {
            $credentials = $channel->credentials ?? [];
            $credentials['business_account_id'] = $validated['whatsapp_business_account_id'] ?: ($credentials['business_account_id'] ?? null);

            foreach (['whatsapp_access_token' => 'access_token', 'whatsapp_app_secret' => 'app_secret'] as $field => $key) {
                if (! empty($validated[$field])) {
                    $credentials[$key] = $validated[$field];
                    $credentialsChanged = true;
                }
            }

            if (($credentials['business_account_id'] ?? null) !== $channel->getWhatsAppBusinessAccountId()) {
                $credentialsChanged = true;
            }
            $channel->update(['credentials' => $credentials]);
        }

        if ($channel->type === 'instagram') {
            $credentials = $channel->credentials ?? [];
            foreach (['instagram_access_token' => 'access_token', 'instagram_app_secret' => 'app_secret'] as $field => $key) {
                if (! empty($validated[$field])) {
                    $credentials[$key] = $validated[$field];
                    $credentialsChanged = true;
                }
            }
            $channel->update(['credentials' => $credentials]);
        }

        if ($channel->type === 'slack') {
            $credentials = $channel->credentials ?? [];
            foreach (['slack_bot_token' => 'bot_token', 'slack_signing_secret' => 'signing_secret'] as $field => $key) {
                if (! empty($validated[$field])) {
                    $credentials[$key] = $validated[$field];
                    $credentialsChanged = true;
                }
            }
            $channel->update(['credentials' => $credentials]);
        }

        if ($credentialsChanged) {
            try {
                $this->reconnectWebhook($channel);
            } catch (\Throwable $e) {
                return redirect()
                    ->route('chat-engine.channels.edit', ['channel' => $channel->id])
                    ->with('error', 'Channel saved, but re-registering the webhook failed: '.$e->getMessage());
            }
        }

        return redirect()
            ->route('chat-engine.channels.index')
            ->with('success', 'Channel "'.$channel->name.'" updated.');
    }

    public function reconnect(Request $request, $token, ChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);
        try {
            $this->reconnectWebhook($channel);
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to reconnect the webhook: '.$e->getMessage());
        }

        return back()->with('success', 'Webhook reconnected.');
    }

    private function reconnectWebhook(ChatChannel $channel): void
    {
        if (! $channel->webhook_secret) {
            $channel->update(['webhook_secret' => Str::random(40)]);
        }

        $adapter = $this->chatEngine->adapterFor($channel->type);
        $webhookUrl = match (true) {
            $channel->type === 'facebook' && $channel->chat_facebook_app_id !== null => route('webhooks.chat.facebook.app', ['facebookApp' => $channel->chat_facebook_app_id]),
            $channel->type === 'facebook' => route('webhooks.chat.facebook'),
            $channel->type === 'whatsapp' => route('webhooks.chat.whatsapp', ['channel' => $channel->id]),
            $channel->type === 'instagram' => route('webhooks.chat.instagram', ['channel' => $channel->id]),
            $channel->type === 'slack' => route('webhooks.chat.slack', ['channel' => $channel->id]),
            default => route('webhooks.chat.telegram', ['channel' => $channel->id]),
        };

        $adapter->setupWebhook($channel, $webhookUrl);
    }

    public function toggle(Request $request, $token, ChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);
        $channel->update(['is_active' => ! $channel->is_active]);

        return back()->with('success', $channel->is_active ? 'Channel activated.' : 'Channel deactivated.');
    }

    public function transferOwnership(Request $request, $token, ChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);
        abort_unless($request->user()?->hasAnyRole(['admin', 'superadmin', 'reseller']), 403);

        $allowedIds = $this->ownershipOptions($request)->pluck('id')->all();
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', Rule::in($allowedIds)],
        ]);

        $channel->update(['created_by' => $validated['owner_id']]);

        return back()->with('success', 'Channel ownership transferred.');
    }

    public function destroy(Request $request, $token, ChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);
        $channel->delete();

        return redirect()->route('chat-engine.channels.index')->with('success', 'Channel deleted.');
    }

    private function authorizeChannel(Request $request, ChatChannel $channel): void
    {
        abort_unless(ChatChannel::query()->visibleTo($request->user())->whereKey($channel->id)->exists(), 403);
    }

    private function ownershipOptions(Request $request)
    {
        $actor = $request->user();

        return User::query()
            ->when(! $actor?->hasAnyRole(['admin', 'superadmin']), fn ($query) => $query->where(fn ($scope) => $scope
                ->whereKey($actor?->id)
                ->orWhere('reseller_id', $actor?->id)))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
