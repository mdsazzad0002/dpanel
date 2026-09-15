<?php

namespace App\Http\Controllers;

use App\Models\ChatChannel;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\ChatEngine\ChatEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Authenticated, panel-only chat with a business's AI assistant — the
 * "internal" counterpart to the public embeddable widget
 * (WebsiteChatWidgetController). Only reachable by a logged-in staff user
 * with access to the channel (see ChatChannel::visibleTo()), so this is
 * where the AI's internal-only tools (send_due_reminders, send_sms,
 * list_due_customers, full search) can actually be exercised — see
 * ChatChannel::isInternal()'s docblock.
 */
class ChatEngineAssistantController extends Controller
{
    public function __construct(private readonly ChatEngineService $chatEngine)
    {
    }

    public function show(Request $request, $token, ChatChannel $channel): Response
    {
        $this->authorizeChannel($request, $channel);

        $conversation = $this->conversationFor($channel, $request);

        $messages = $conversation
            ? $conversation->messages()->orderBy('created_at')->get()->map(fn (ChatMessage $m): array => [
                'id' => $m->id,
                'direction' => $m->direction,
                'role' => $m->role,
                'content' => $m->content,
                'created_at' => $m->created_at?->toDateTimeString(),
            ])
            : collect();

        return Inertia::render('ChatEngine/Assistant/Show', [
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'internal_access' => $channel->isInternal(),
                'business' => $channel->business ? ['id' => $channel->business->id, 'name' => $channel->business->name] : null,
            ],
            'messages' => $messages,
        ]);
    }

    public function send(Request $request, $token, ChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($request, $channel);
        abort_unless($channel->type === 'website' && $channel->is_active, 404);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $adapter = $this->chatEngine->adapterFor('website');
        $inbound = $adapter->normalizeInbound($channel, [
            'contact_token' => $this->contactToken($channel, $request),
            'message' => $validated['message'],
        ]);

        if ($inbound) {
            $this->chatEngine->handleInboundMessage($channel, $inbound);
        }

        return back();
    }

    /**
     * A stable per-(channel, staff user) identity, distinct from any
     * public widget visitor, so each staff member gets their own
     * conversation with the assistant on the same internal channel.
     */
    private function contactToken(ChatChannel $channel, Request $request): string
    {
        return 'staff-'.$request->user()->id;
    }

    private function conversationFor(ChatChannel $channel, Request $request): ?ChatConversation
    {
        $contact = ChatContact::query()
            ->where('chat_channel_id', $channel->id)
            ->where('external_id', $this->contactToken($channel, $request))
            ->first();

        if (! $contact) {
            return null;
        }

        return ChatConversation::query()
            ->where('chat_channel_id', $channel->id)
            ->where('chat_contact_id', $contact->id)
            ->first();
    }

    private function authorizeChannel(Request $request, ChatChannel $channel): void
    {
        abort_unless(ChatChannel::query()->visibleTo($request->user())->whereKey($channel->id)->exists(), 403);
    }
}
