<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundChatMessageJob;
use App\Models\ChatChannel;
use App\Services\ChatEngine\Providers\InstagramAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InstagramChatWebhookController extends Controller
{
    public function __construct(private readonly InstagramAdapter $adapter)
    {
    }

    public function verify(Request $request, ChatChannel $channel): Response|JsonResponse
    {
        if ($channel->type !== 'instagram'
            || $request->query('hub_mode', $request->query('hub.mode')) !== 'subscribe'
            || ! $channel->webhook_secret
            || ! hash_equals($channel->webhook_secret, (string) $request->query('hub_verify_token', $request->query('hub.verify_token')))) {
            return response()->json(['error' => 'verification failed'], 403);
        }

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge')), 200);
    }

    public function store(Request $request, ChatChannel $channel): JsonResponse
    {
        abort_unless($channel->type === 'instagram' && $channel->is_active && $this->adapter->verifyWebhook($channel, $request), 403);

        foreach ((array) $request->input('entry', []) as $entry) {
            if ((string) ($entry['id'] ?? '') !== (string) $channel->external_account_id) {
                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $message) {
                ProcessInboundChatMessageJob::dispatch($channel->id, $message)->afterCommit();
            }
        }

        return response()->json(['ok' => true]);
    }
}
