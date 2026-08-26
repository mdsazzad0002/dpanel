<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundChatMessageJob;
use App\Models\ChatChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramChatWebhookController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var ChatChannel $channel */
        $channel = $request->attributes->get('chat_channel');

        ProcessInboundChatMessageJob::dispatch($channel->id, $request->all())->afterCommit();

        return response()->json(['ok' => true]);
    }
}
