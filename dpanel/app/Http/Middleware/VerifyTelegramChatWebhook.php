<?php

namespace App\Http\Middleware;

use App\Models\ChatChannel;
use App\Services\ChatEngine\ChatEngineService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramChatWebhook
{
    public function __construct(private readonly ChatEngineService $chatEngine)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $channel = ChatChannel::where('id', $request->route('channel'))
            ->where('type', 'telegram')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            abort(404);
        }

        $adapter = $this->chatEngine->adapterFor('telegram');

        if (! $adapter->verifyWebhook($channel, $request)) {
            abort(403, 'Invalid webhook signature.');
        }

        $request->attributes->set('chat_channel', $channel);

        return $next($request);
    }
}
