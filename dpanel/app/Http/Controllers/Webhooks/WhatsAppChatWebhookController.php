<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundChatMessageJob;
use App\Models\ChatChannel;
use App\Services\ChatEngine\Providers\WhatsAppAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppChatWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppAdapter $adapter)
    {
    }

    public function verify(Request $request, ChatChannel $channel): Response|JsonResponse
    {
        if ($channel->type !== 'whatsapp'
            || $request->query('hub_mode', $request->query('hub.mode')) !== 'subscribe'
            || ! $channel->webhook_secret
            || ! hash_equals($channel->webhook_secret, (string) $request->query('hub_verify_token', $request->query('hub.verify_token')))) {
            return response()->json(['error' => 'verification failed'], 403);
        }

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge')), 200);
    }

    public function store(Request $request, ChatChannel $channel): JsonResponse
    {
        abort_unless($channel->type === 'whatsapp' && $channel->is_active && $this->adapter->verifyWebhook($channel, $request), 403);

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = (array) ($change['value'] ?? []);
                if ((string) data_get($value, 'metadata.phone_number_id') !== (string) $channel->external_account_id) {
                    continue;
                }

                $contacts = collect($value['contacts'] ?? [])->keyBy('wa_id');
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    ProcessInboundChatMessageJob::dispatch($channel->id, [
                        'message' => $message,
                        'contact' => $contacts->get($message['from'] ?? '', []),
                        'metadata' => $value['metadata'] ?? [],
                    ])->afterCommit();
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
