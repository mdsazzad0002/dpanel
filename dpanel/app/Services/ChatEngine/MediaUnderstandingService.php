<?php

namespace App\Services\ChatEngine;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one shared entry point every channel adapter (Telegram, WhatsApp,
 * website widget, ...) uses to turn a voice note or an image attachment
 * into text before it's handed to the normal chat pipeline. The actual
 * speech-to-text (whisper.cpp) and OCR (tesseract) work happens in the
 * self-hosted drust execution API (see /var/www/drust/src/api/media.rs) —
 * self-hosted specifically so short voice notes come back in a few seconds
 * instead of round-tripping to an external AI vision/audio API. Every call
 * fails soft (never throws) so a broken/slow media call degrades the reply
 * instead of breaking it.
 */
class MediaUnderstandingService
{
    /**
     * @param string $url Publicly reachable URL of the voice note (the platform's own CDN URL is fine — drust downloads it directly).
     * @param string|null $language ISO 639-1 hint (e.g. "bn", "en"); null/"auto" lets whisper.cpp detect it.
     * @param array<string,string> $mediaHeaders Extra headers drust should send when downloading $url — e.g. WhatsApp/Slack media URLs 403 without the platform's own bearer token, unlike Telegram/Facebook/Instagram whose attachment URLs are already public.
     */
    public function transcribeAudio(string $url, ?string $language = null, array $mediaHeaders = []): ?string
    {
        return $this->call(
            (string) config('serverpanel.media_transcribe_api_url'),
            $url,
            $language,
            $mediaHeaders,
        );
    }

    public function extractTextFromImage(string $url, array $mediaHeaders = []): ?string
    {
        return $this->call((string) config('serverpanel.media_ocr_api_url'), $url, null, $mediaHeaders);
    }

    /**
     * @param array<string,string> $mediaHeaders
     */
    private function call(string $apiUrl, string $url, ?string $language = null, array $mediaHeaders = []): ?string
    {
        if ($apiUrl === '') {
            Log::warning('ChatEngine: media understanding skipped, no API URL configured', [
                'url' => $url,
            ]);

            return null;
        }

        try {
            $token = (string) config('serverpanel.execution_api_token');
            $request = Http::timeout(20)->acceptJson();

            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->post($apiUrl, array_filter([
                'url' => $url,
                'language' => $language,
                'headers' => $mediaHeaders !== [] ? $mediaHeaders : null,
            ], fn ($value) => $value !== null));

            if (! $response->successful() || ! $response->json('success')) {
                Log::warning('ChatEngine: media understanding call did not succeed', [
                    'api_url' => $apiUrl,
                    'media_url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $text = trim((string) $response->json('data.text'));

            if ($text === '') {
                Log::warning('ChatEngine: media understanding returned an empty transcript', [
                    'api_url' => $apiUrl,
                    'media_url' => $url,
                ]);

                return null;
            }

            return $text;
        } catch (\Throwable $e) {
            Log::warning('ChatEngine: media understanding call failed', [
                'api_url' => $apiUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
