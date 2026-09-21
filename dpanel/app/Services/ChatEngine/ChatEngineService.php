<?php

namespace App\Services\ChatEngine;

use App\Models\ChatChannel;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AiGateway\AiGatewayService;
use App\Services\ChatEngine\Contracts\ChannelAdapter;
use App\Services\ChatEngine\DTO\InboundMessage;
use App\Services\ChatEngine\Exceptions\ChatEngineException;
use App\Services\ChatEngine\Providers\FacebookAdapter;
use App\Services\ChatEngine\Providers\TelegramAdapter;
use App\Services\ChatEngine\Providers\WhatsAppAdapter;
use App\Services\ChatEngine\Providers\InstagramAdapter;
use App\Services\ChatEngine\Providers\SlackAdapter;
use App\Services\ChatEngine\Providers\WebsiteAdapter;
use Illuminate\Support\Facades\Log;

class ChatEngineService
{
    /**
     * @var array<int, ChannelAdapter>
     */
    private array $adapters;

    public function __construct(
        private readonly AiGatewayService $aiGateway,
        private readonly BusinessKnowledgeService $businessKnowledge,
        private readonly BusinessToolService $businessTools,
        private readonly ContactCaptureService $contactCapture,
    ) {
        $media = app(MediaUnderstandingService::class);

        $this->adapters = [
            new TelegramAdapter($media),
            new FacebookAdapter($media),
            new WhatsAppAdapter($media),
            new InstagramAdapter($media),
            new SlackAdapter($media),
            new WebsiteAdapter(),
        ];
    }

    public function adapterFor(string $type): ChannelAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->driver() === $type) {
                return $adapter;
            }
        }

        throw ChatEngineException::unsupportedChannelType($type);
    }

    /**
     * Handle one inbound message end to end: persist it, generate an AI
     * reply when enabled, and send that reply back out on the channel.
     */
    public function handleInboundMessage(ChatChannel $channel, InboundMessage $inbound): void
    {
        $contact = ChatContact::firstOrCreate(
            ['chat_channel_id' => $channel->id, 'external_id' => $inbound->externalContactId],
            ['name' => $inbound->contactName, 'username' => $inbound->contactUsername]
        );

        $contact->fill([
            'name' => $inbound->contactName ?: $contact->name,
            'username' => $inbound->contactUsername ?: $contact->username,
            'last_message_at' => now(),
        ])->save();

        $conversation = ChatConversation::firstOrCreate(
            ['chat_channel_id' => $channel->id, 'chat_contact_id' => $contact->id],
            ['is_ai_enabled' => true],
        );
        $conversation->update(['last_message_at' => now()]);

        ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'role' => 'user',
            'content' => $inbound->text,
            'type' => $inbound->kind === 'comment' ? 'comment' : 'text',
            'external_message_id' => $inbound->externalMessageId,
            'meta' => ['reply_context' => $inbound->replyContext],
        ]);

        if (! $conversation->is_ai_enabled || ! $channel->isAutoReplyEnabled()) {
            return;
        }

        if ($channel->business?->hasReachedAiReplyLimit()) {
            Log::warning('ChatEngine: AI reply skipped, business reply credit exhausted', [
                'channel_id' => $channel->id,
                'business_id' => $channel->business->id,
            ]);

            return;
        }

        try {
            $this->replyWithAi($channel, $conversation, $inbound->replyContext);
        } catch (\Throwable $e) {
            Log::error('ChatEngine: AI reply failed', [
                'channel_id' => $channel->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function replyWithAi(ChatChannel $channel, ChatConversation $conversation, array $replyContext): void
    {
        // ChatConversation::messages() bakes in ->orderBy('created_at')
        // (oldest first), so limit()-ing that directly always returns the
        // SAME oldest N messages no matter how long the conversation has
        // grown — the AI never saw anything past the first
        // context_message_limit messages ever exchanged. reorder() clears
        // that before applying our own newest-first order (by id, a
        // time-ordered UUIDv7 — created_at alone ties within the same
        // second in a fast exchange, which has silently reordered the
        // just-created inbound message before an earlier outbound reply;
        // for providers like Gemini that reject a transcript ending on a
        // model turn, that tie has caused the request to fail outright).
        $messages = $conversation->messages()
            ->reorder('id', 'desc')
            ->limit(config('chatengine.context_message_limit'))
            ->get()
            ->sortBy('id')
            ->map(fn (ChatMessage $m): array => [
                'role' => $m->role === 'user' ? 'user' : 'assistant',
                'content' => (string) $m->content,
            ])
            ->values()
            ->all();

        $system = $this->businessKnowledge->buildSystemPrompt($channel);
        $business = $channel->business;
        $internal = $channel->isInternal();
        $businessToolList = $business ? $this->businessTools->availableTools($business, $internal) : [];
        $tools = [...$businessToolList, $this->contactCapture->tool()];

        if (! $internal) {
            $system .= "\n\nThis is a public, unauthenticated conversation (e.g. a website visitor, not someone logged into the business's own "
                .'panel). Never reveal another person\'s private details here — a customer\'s phone number, outstanding due, order history, or '
                .'similar — even if directly asked or if a tool happens to return it. Only discuss the current requester\'s own order/details '
                .'once they themselves have provided enough to identify it (e.g. their own order number or phone number they just gave you).';
        }
        $hasSearchTools = collect($businessToolList)->contains(fn (array $t): bool => $t['function']['name'] === 'search');
        $hasActionTools = collect($businessToolList)->contains(fn (array $t): bool => $t['function']['name'] !== 'search');

        if ($hasSearchTools) {
            $system .= "\n\nYou also have search tools available that look up live information directly on this business's own site. "
                .'Before telling the customer you don\'t have information, check whether a search tool could answer it and use one if relevant. '
                .'This is a real function call you make yourself in the background, invisible to the requester — it is never something you ask '
                .'them to do or wait for permission for. If a name, phone number, product, or invoice is mentioned and you don\'t already know '
                .'its details, call the search tool with it BEFORE writing any reply — do not ask the requester a question that a search call '
                .'could answer instead.';
        }

        if ($hasActionTools) {
            $system .= "\n\nYou also have tools available to take real actions (place an order, send an email, send an SMS). "
                .'Only call a tool when the requester has clearly asked for that action and you have all the required details. '
                .'If a detail is about someone already in the business\'s own records (e.g. an existing customer\'s phone number for an SMS), '
                .'use a search tool to look it up yourself before asking anyone to repeat information the business already has — only ask '
                .'the requester directly for details nobody else could know, like the message text to send or a brand-new customer\'s contact '
                .'info. Never ask the requester for their own name/phone/email just to carry out an action tool — that\'s only for save_contact_info below. '
                ."Example: requester says \"send Sazzad an SMS about their due\" and you have a search tool. Do NOT reply asking for Sazzad's "
                .'phone number — call search with "Sazzad" FIRST in this same turn. If it returns a phone number and due amount, either call '
                .'send_sms immediately with a reasonable due-reminder message, or if you need the exact wording, ask the requester ONLY for '
                .'the message text (never the phone number you already found). Only ask for the phone number if search genuinely found no match. '
                .'If YOU asked the requester for a specific missing detail to complete an action (a phone number, the message text, an address, '
                .'...) and their very next message supplies just that — a bare phone number, a short line of text — treat it as the answer to '
                .'your own question and immediately call the action tool with it. Do not call save_contact_info instead, and do not just '
                .'acknowledge it and say your team will handle it later — you have the tool, use it now, in this same turn.';
        }

        if (collect($businessToolList)->contains(fn (array $t): bool => $t['function']['name'] === 'list_due_customers')) {
            $system .= "\n\nWhen asked to remind/notify everyone (or a whole group) who has an outstanding due, rather than one named customer, "
                .'this is a two-step process using two different tools — never call both in the same turn: '
                ."Step 1 — call list_due_customers, then reply summarizing what you'd send: how many customers, and the exact reminder wording "
                .'you plan to use as a template (e.g. "Priyo {name}, apnar baki {due} taka." — {name} and {due} are placeholders send_due_reminders '
                .'fills in per customer, so describe it to the requester in plain language, not with the literal placeholders). Ask the requester '
                .'to confirm before anything goes out. Do not call send_due_reminders in this reply. '
                .'Step 2 — only once the requester clearly confirms (e.g. "yes", "send it", "go ahead") in a later message, call send_due_reminders '
                .'ONCE with that message template — it texts every customer who currently has a due itself, you do not call send_sms for this. '
                .'If the list was empty at step 1, say so and stop — do not invent customers, and do not call send_due_reminders for an empty list. '
                .'This is only ever available on an internal/authenticated conversation.';
        }

        $system .= "\n\nYou also have a save_contact_info tool. When you can't fully resolve something yourself — the answer isn't in the business "
            .'knowledge, the customer wants a quote/callback, or a team member needs to follow up (not when an action tool above can already fully '
            .'carry out what was asked, and not when a phone/email/name you just received was the answer to something YOU asked for to complete '
            .'an action tool call — that value belongs to that action tool, not to save_contact_info) — the professional move is to get the '
            .'*customer\'s own* phone number so your team can reach them, not to just hand them the business\'s number and end the conversation there. '
            .'Ask for it naturally as part of handing them off (e.g. "Could I get a contact number so our team can reach you about this?"), '
            .'call save_contact_info the moment they give their name, phone, or email — even if the others are still missing — and don\'t ask again '
            .'once you have it. Never interrogate the customer for contact details when nothing in the conversation actually requires them yet. '
            .'Whenever you call save_contact_info, your reply must acknowledge it — thank them by name and confirm a team member will follow up — '
            ."instead of repeating your previous message. If the customer's actual question also isn't covered by the business knowledge below, "
            .'fold that acknowledgment together with the fallback line rather than sending the fallback on its own.';

        $workingMessages = $messages;
        $result = null;

        // Up to 3 tool-calling rounds: the model may need one order/email/sms
        // action, or a couple in sequence, before it has a final answer.
        for ($round = 0; $round < 3; $round++) {
            $result = $this->aiGateway->chatAuto(null, $workingMessages, [
                'system' => $system,
                'channel' => $channel->type,
                'operation' => 'chat',
                'tools' => $tools ?: null,
            ]);

            if (empty($result['tool_calls'])) {
                break;
            }

            $workingMessages[] = [
                'role' => 'assistant',
                'content' => $result['content'] ?? '',
                'tool_calls' => $result['tool_calls'],
            ];

            foreach ($result['tool_calls'] as $call) {
                $arguments = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
                $toolName = $call['function']['name'] ?? '';
                $toolResult = $toolName === 'save_contact_info'
                    ? $this->contactCapture->execute($conversation->contact, $arguments)
                    : $this->businessTools->execute($business, $toolName, $arguments, (string) $conversation->id, $internal);

                $workingMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'] ?? '',
                    'content' => $toolResult,
                ];
            }
        }

        $reply = trim((string) ($result['content'] ?? ''));

        if ($reply === '' && ! empty($result['tool_calls'])) {
            // The model was still requesting tool calls when the round cap
            // was hit — force one last text-only completion instead of
            // silently dropping the reply (the customer would otherwise
            // never hear back even though every tool call succeeded).
            $result = $this->aiGateway->chatAuto(null, $workingMessages, [
                'system' => $system,
                'channel' => $channel->type,
                'operation' => 'chat',
            ]);

            $reply = trim((string) ($result['content'] ?? ''));
        }

        if ($reply === '') {
            Log::warning('ChatEngine: AI produced an empty reply', [
                'channel_id' => $channel->id,
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $message = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'role' => 'assistant',
            'content' => $reply,
            'type' => ($replyContext['kind'] ?? null) === 'comment' ? 'comment' : 'text',
            'ai_trace_id' => $result['trace_id'],
            'meta' => ['reply_context' => $replyContext],
        ]);

        $business?->recordAiReply();

        $this->sendReply($channel, $conversation, $message);
    }

    /**
     * The context of the most recent inbound message in a conversation —
     * used to route a manual (human) reply the same way an AI reply to that
     * same message would have been routed (e.g. back to the right Facebook
     * comment rather than as a private message).
     */
    public function latestInboundReplyContext(ChatConversation $conversation): array
    {
        $latest = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->first();

        return ($latest?->meta ?? [])['reply_context'] ?? [];
    }

    public function sendReply(ChatChannel $channel, ChatConversation $conversation, ChatMessage $message): void
    {
        $contact = $conversation->contact;
        $adapter = $this->adapterFor($channel->type);
        $context = ($message->meta ?? [])['reply_context'] ?? [];

        try {
            $result = $adapter->sendMessage($channel, $contact->external_id, (string) $message->content, $context);
            $message->update(['status' => 'sent', 'external_message_id' => $result['external_message_id'] ?? $message->external_message_id]);
        } catch (\Throwable $e) {
            $message->update(['status' => 'failed']);
            throw $e;
        }
    }
}
