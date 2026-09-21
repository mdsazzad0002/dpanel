<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundChatMessageJob;
use App\Models\ChatChannel;
use App\Services\ChatEngine\Providers\SlackAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlackChatWebhookController extends Controller
{
    public function __construct(private readonly SlackAdapter $adapter)
    {
    }

    public function store(Request $request, ChatChannel $channel): JsonResponse
    {
        abort_unless($channel->type === 'slack' && $channel->is_active && $this->adapter->verifyWebhook($channel, $request), 403);

        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        if ($request->input('type') === 'event_callback' && is_array($request->input('event'))) {
            ProcessInboundChatMessageJob::dispatch($channel->id, $request->input('event'))->afterCommit();
        }

        return response()->json(['ok' => true]);
    }
}
