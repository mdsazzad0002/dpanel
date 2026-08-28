<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ChatChannel;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Services\ChatEngine\ChatEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public, unauthenticated, CORS-enabled endpoint for the embeddable website
 * widget (public/widget/chat.js). Unlike the async webhook channels, this
 * responds synchronously: the AI reply is generated inline and returned in
 * the same HTTP response, so the browser widget has nothing to poll.
 */
class WebsiteChatWidgetController extends Controller
{
    public function __construct(private readonly ChatEngineService $chatEngine)
    {
    }

    public function send(Request $request, ChatChannel $channel): JsonResponse
    {
        abort_unless($channel->type === 'website' && $channel->is_active, 404);

        $validated = $request->validate([
            'contact_token' => ['nullable', 'string', 'max:64'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $contactToken = $validated['contact_token'] ?? Str::random(40);

        $adapter = $this->chatEngine->adapterFor('website');
        $inbound = $adapter->normalizeInbound($channel, [
            'contact_token' => $contactToken,
            'message' => $validated['message'],
        ]);

        if (! $inbound) {
            return response()->json(['contact_token' => $contactToken, 'reply' => null]);
        }

        $since = now();
        $this->chatEngine->handleInboundMessage($channel, $inbound);

        $contact = ChatContact::query()
            ->where('chat_channel_id', $channel->id)
            ->where('external_id', $contactToken)
            ->first();

        $reply = null;

        if ($contact) {
            $conversation = ChatConversation::query()
                ->where('chat_channel_id', $channel->id)
                ->where('chat_contact_id', $contact->id)
                ->first();

            $reply = $conversation?->messages()
                ->where('direction', 'outbound')
                ->where('created_at', '>=', $since)
                ->orderBy('created_at')
                ->value('content');
        }

        return response()->json([
            'contact_token' => $contactToken,
            'reply' => $reply,
        ]);
    }
}
