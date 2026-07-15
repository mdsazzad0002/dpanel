<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramWebhookController extends Controller
{
    public function url(Request $request): JsonResponse
    {
        $token = trim((string) $request->query('token', ''));

        return response()->json([
            'webhook_url' => route('telegram.webhook', absolute: true),
            'url_with_token' => $token !== ''
                ? route('telegram.webhook-url', ['token' => $token], absolute: true)
                : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $message = (array) $request->input('message', []);
        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '' || ! preg_match('/^\/start(?:\s+(.+))?$/i', $text, $matches)) {
            return response()->json(['ok' => true]);
        }

        $token = trim((string) ($matches[1] ?? ''));
        if ($token === '') {
            return response()->json(['ok' => true]);
        }

        $user = User::query()
            ->where('two_factor_telegram_start_token', $token)
            ->first();

        if (! $user instanceof User) {
            return response()->json(['ok' => true]);
        }

        $chatId = trim((string) data_get($message, 'chat.id', ''));

        $user->two_factor_enabled = true;
        $user->two_factor_method = 'telegram';
        $user->two_factor_telegram_chat_id = $chatId;
        $user->two_factor_telegram_start_token = null;

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->save();

        $this->sendLinkResponse($chatId, $token);

        return response()->json(['ok' => true]);
    }

    private function sendLinkResponse(string $chatId, string $token): void
    {
        $botToken = (string) config('services.telegram.bot_token', '');
        if ($botToken === '') {
            return;
        }

        $url = route('telegram.webhook-url', ['token' => $token], absolute: true);

        Http::timeout(30)
            ->withoutVerifying()
            ->acceptJson()
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "Telegram verification linked successfully.\n\nOpen this URL: {$url}",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => 'Open URL',
                                'url' => $url,
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ]);
    }
}
