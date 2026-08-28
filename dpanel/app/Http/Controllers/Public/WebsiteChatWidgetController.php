<?php

namespace App\Http\Controllers\Public;

use App\Models\ChatChannel;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Http\Controllers\Controller;
use App\Services\ChatEngine\ChatEngineService;
use App\Services\ChatEngine\MediaUnderstandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Public, unauthenticated, CORS-enabled endpoint for the embeddable website
 * widget (public/widget/chat.js). Unlike the async webhook channels, this
 * responds synchronously: the AI reply is generated inline and returned in
 * the same HTTP response, so the browser widget has nothing to poll.
 */
class WebsiteChatWidgetController extends Controller
{
    private const MAX_MEDIA_BYTES_KB = 20 * 1024;

    public function __construct(
        private readonly ChatEngineService $chatEngine,
        private readonly MediaUnderstandingService $media,
    ) {
    }

    public function send(Request $request, ChatChannel $channel): JsonResponse
    {
        abort_unless($channel->type === 'website' && $channel->is_active, 404);

        $validated = $request->validate([
            'contact_token' => ['nullable', 'string', 'max:64'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        return $this->reply($channel, $validated['contact_token'] ?? null, $validated['message']);
    }

    /**
     * Voice/image upload counterpart to send(). The widget can't attach
     * binary bytes to a URL the way Telegram/Facebook/Instagram's
     * platform-hosted attachments do, so the file comes to us directly as
     * multipart form data. drust only takes a URL (one consistent contract
     * across every channel, not raw bytes), so the file is briefly served
     * back out through a random, short-lived, cache-backed token — same
     * pattern as QuickExportLinkFactory's download links — rather than
     * ever being written into the public webroot, and is deleted the
     * moment drust has fetched it (or the request ends), whichever first.
     */
    public function media(Request $request, ChatChannel $channel): JsonResponse
    {
        abort_unless($channel->type === 'website' && $channel->is_active, 404);

        $isAudio = $request->input('type') === 'audio';

        $validated = $request->validate([
            'contact_token' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:image,audio'],
            'file' => $isAudio
                ? ['required', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,audio/webm,audio/aac,audio/mp4,audio/x-m4a,audio/3gpp,audio/amr', 'max:'.self::MAX_MEDIA_BYTES_KB]
                : ['required', 'image', 'max:'.self::MAX_MEDIA_BYTES_KB],
        ]);

        $tempDir = storage_path('app/widget-media-tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $file = $validated['file'];
        $tempName = Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin');
        $file->move($tempDir, $tempName);
        $tempPath = $tempDir.'/'.$tempName;

        $mediaToken = Str::random(40);
        Cache::put('widget-media:'.$mediaToken, $tempPath, now()->addMinutes(5));
        $url = route('widget.media.show', ['token' => $mediaToken]);

        try {
            $text = $validated['type'] === 'audio'
                ? ($this->media->transcribeAudio($url) ?: '[The customer sent a voice message that could not be transcribed. Ask them to type their message instead.]')
                : ($this->media->extractTextFromImage($url) ?: '[The customer sent an image with no readable text in it. This assistant cannot see image contents, only read text within them — ask the customer to describe what they need.]');
        } finally {
            Cache::forget('widget-media:'.$mediaToken);
            @unlink($tempPath);
        }

        return $this->reply($channel, $validated['contact_token'] ?? null, $text);
    }

    /**
     * One-shot fetch target for the token media() just created — drust is
     * the only expected caller, within seconds of the upload.
     */
    public function showMedia(string $token): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $path = Cache::get('widget-media:'.$token);

        abort_unless(is_string($path) && file_exists($path), 404);

        return response()->file($path);
    }

    private function reply(ChatChannel $channel, ?string $contactToken, string $messageText): JsonResponse
    {
        $contactToken = $contactToken ?: Str::random(40);

        $adapter = $this->chatEngine->adapterFor('website');
        $inbound = $adapter->normalizeInbound($channel, [
            'contact_token' => $contactToken,
            'message' => $messageText,
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
