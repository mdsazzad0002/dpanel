<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Services\ChatEngine\ChatEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatEngineConversationController extends Controller
{
    public function __construct(private readonly ChatEngineService $chatEngine)
    {
    }

    public function index(Request $request): Response
    {
        $conversations = ChatConversation::query()
            ->whereIn('chat_channel_id', ChatChannel::query()->visibleTo($request->user())->select('id'))
            ->with(['channel:id,name,type', 'contact:id,name,username,external_id'])
            ->when($request->filled('channel_id'), fn ($q) => $q->where('chat_channel_id', $request->input('channel_id')))
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (ChatConversation $c): array => [
                'id' => $c->id,
                'channel_name' => $c->channel->name,
                'channel_type' => $c->channel->type,
                'contact_name' => $c->contact->name ?: $c->contact->username ?: $c->contact->external_id,
                'is_ai_enabled' => $c->is_ai_enabled,
                'status' => $c->status,
                'last_message_at' => $c->last_message_at?->toDateTimeString(),
            ]);

        return Inertia::render('ChatEngine/Conversations/Index', [
            'conversations' => $conversations,
            'filterChannelId' => $request->input('channel_id'),
        ]);
    }

    public function show($token, ChatConversation $conversation): Response
    {
        $this->authorizeConversation(request(), $conversation);
        $conversation->load(['channel:id,name,type', 'contact:id,name,username,external_id']);

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (ChatMessage $m): array => [
                'id' => $m->id,
                'direction' => $m->direction,
                'role' => $m->role,
                'content' => $m->content,
                'type' => $m->type,
                'status' => $m->status,
                'created_at' => $m->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('ChatEngine/Conversations/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'channel_name' => $conversation->channel->name,
                'contact_name' => $conversation->contact->name ?: $conversation->contact->username ?: $conversation->contact->external_id,
                'is_ai_enabled' => $conversation->is_ai_enabled,
            ],
            'messages' => $messages,
        ]);
    }

    public function toggleAi(Request $request, $token, ChatConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);
        $conversation->update(['is_ai_enabled' => ! $conversation->is_ai_enabled]);

        return back()->with('success', $conversation->is_ai_enabled ? 'AI auto-reply enabled.' : 'AI auto-reply paused — replies will be manual.');
    }

    public function reply(Request $request, $token, ChatConversation $conversation): RedirectResponse
    {
        $this->authorizeConversation($request, $conversation);
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ]);

        $replyContext = $this->chatEngine->latestInboundReplyContext($conversation);

        $message = ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'role' => 'agent',
            'content' => $validated['content'],
            'type' => ($replyContext['kind'] ?? null) === 'comment' ? 'comment' : 'text',
            'meta' => ['reply_context' => $replyContext],
        ]);

        try {
            $this->chatEngine->sendReply($conversation->channel, $conversation, $message);
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to send reply: '.$e->getMessage());
        }

        $conversation->update(['last_message_at' => now()]);

        return back()->with('success', 'Reply sent.');
    }

    private function authorizeConversation(Request $request, ChatConversation $conversation): void
    {
        abort_unless(ChatChannel::query()->visibleTo($request->user())->whereKey($conversation->chat_channel_id)->exists(), 403);
    }
}
