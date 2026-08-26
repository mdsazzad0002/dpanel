<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundChatMessageJob;
use App\Models\ChatChannel;
use App\Models\ChatFacebookApp;
use App\Models\FacebookPageActivity;
use App\Services\ChatEngine\Providers\FacebookAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * One Facebook App's webhook covers every Page connected through it, so —
 * unlike Telegram's per-channel webhook URL — this endpoint has to resolve
 * which ChatChannel each entry belongs to itself (Facebook's payload
 * includes the Page id per entry, possibly several pages in one request).
 * $facebookApp is null only on the legacy shared URL kept for channels
 * connected manually before per-app registration existed.
 */
class FacebookChatWebhookController extends Controller
{
    public function __construct(private readonly FacebookAdapter $adapter)
    {
    }

    /**
     * Meta's one-time webhook subscription handshake.
     */
    public function verify(Request $request, ?ChatFacebookApp $facebookApp = null): Response|JsonResponse
    {
        if ($request->query('hub_mode', $request->query('hub.mode')) !== 'subscribe') {
            return response()->json(['error' => 'verification failed'], 403);
        }

        $submittedToken = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $expectedTokens = $facebookApp
            ? [$facebookApp->verify_token]
            : array_filter([config('chatengine.facebook.verify_token')]);

        foreach ($expectedTokens as $token) {
            if ($token && hash_equals((string) $token, $submittedToken)) {
                return response($request->query('hub_challenge', $request->query('hub.challenge')), 200);
            }
        }

        return response()->json(['error' => 'verification failed'], 403);
    }

    public function store(Request $request, ?ChatFacebookApp $facebookApp = null): JsonResponse
    {
        $verified = $facebookApp
            ? $this->adapter->verifySignatureForApp($request, $facebookApp)
            : $this->adapter->verifyAppSignature($request);

        if (! $verified) {
            abort(403, 'Invalid webhook signature.');
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');

            if ($pageId === '') {
                continue;
            }

            $channel = ChatChannel::query()
                ->where('type', 'facebook')
                ->where('external_account_id', $pageId)
                ->where('is_active', true)
                // On a per-app URL, only dispatch events for pages actually
                // connected through *this* app — keeps one tenant's webhook
                // from ever touching another tenant's channel.
                ->when($facebookApp, fn ($q) => $q->where('chat_facebook_app_id', $facebookApp->id))
                ->first();

            if (! $channel) {
                Log::info('ChatEngine: Facebook webhook for unknown/inactive page', ['page_id' => $pageId, 'app_id' => $facebookApp?->id]);

                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $messaging) {
                ProcessInboundChatMessageJob::dispatch($channel->id, ['type' => 'messaging', 'data' => $messaging])->afterCommit();
            }

            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $field = $change['field'] ?? null;
                $item = $change['value']['item'] ?? null;

                Log::info('ChatEngine: Facebook Page change received', [
                    'page_id' => $pageId,
                    'app_id' => $facebookApp?->id,
                    'field' => $field,
                    'item' => $item,
                    'verb' => $change['value']['verb'] ?? null,
                ]);

                if ($field === 'feed') {
                    $value = (array) ($change['value'] ?? []);
                    $externalId = (string) ($value['comment_id'] ?? $value['post_id'] ?? '');

                    if ($externalId !== '') {
                        FacebookPageActivity::updateOrCreate(
                            [
                                'chat_channel_id' => $channel->id,
                                'activity_type' => $item === 'comment' ? 'comment' : 'post',
                                'external_id' => $externalId,
                            ],
                            [
                                'parent_post_id' => $value['post_id'] ?? null,
                                'message' => $value['message'] ?? null,
                                'actor_id' => $value['from']['id'] ?? null,
                                'actor_name' => $value['from']['name'] ?? null,
                                'verb' => $value['verb'] ?? null,
                                'occurred_at' => isset($value['created_time'])
                                    ? \Illuminate\Support\Carbon::createFromTimestamp((int) $value['created_time'])
                                    : now(),
                            ],
                        );
                    }
                }

                if ($field !== 'feed' || $item !== 'comment') {
                    continue;
                }

                Log::info('ChatEngine: Facebook comment queued', [
                    'page_id' => $pageId,
                    'channel_id' => $channel->id,
                    'comment_id' => $change['value']['comment_id'] ?? null,
                ]);

                ProcessInboundChatMessageJob::dispatch($channel->id, ['type' => 'comment', 'data' => $change['value']])->afterCommit();
            }
        }

        return response()->json(['ok' => true]);
    }
}
