<?php

namespace App\Services\AiGateway;

use App\Models\AiGatewayModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Images in the AI Gateway: ones people attach to a chat and ones image
 * models generate. Files live on the local disk under
 * ai-gateway/media/{user}/{id}.{ext}, so only the panel can serve them.
 *
 * In messages an image travels as `images: [{mime, data}]` (base64) next to
 * `content`; each adapter turns that into its provider's own format. A model
 * tagged with the "vision" capability gets the images themselves; any other
 * model gets the text drust's OCR (tesseract) reads from them instead.
 */
class GatewayMedia
{
    public const MIME_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Images per message. */
    public const MAX_IMAGES = 8;

    /** @var array<string, string|null> OCR results by image hash, so failover doesn't read an image twice. */
    private array $ocrCache = [];

    private function disk()
    {
        return Storage::disk('local');
    }

    /**
     * @return array{id: string, mime: string}
     */
    public function store(int $userId, string $bytes, string $mime): array
    {
        $extension = self::MIME_TYPES[$mime] ?? throw new \InvalidArgumentException('Images must be PNG, JPEG, WebP or GIF.');
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Images can be at most '.(self::MAX_BYTES / 1024 / 1024).' MB.');
        }

        $id = (string) Str::uuid();
        $this->disk()->put("ai-gateway/media/{$userId}/{$id}.{$extension}", $bytes);

        return ['id' => $id, 'mime' => $mime];
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public function find(int $userId, string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        foreach (self::MIME_TYPES as $mime => $extension) {
            $path = "ai-gateway/media/{$userId}/{$id}.{$extension}";
            if ($this->disk()->exists($path)) {
                return ['path' => $path, 'mime' => $mime];
            }
        }

        return null;
    }

    /**
     * The message shape adapters take, from stored image ids.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array{mime: string, data: string}>
     */
    public function forMessage(int $userId, array $ids): array
    {
        return array_map(function (string $id) use ($userId): array {
            $file = $this->find($userId, $id) ?? throw new \InvalidArgumentException('An attached image no longer exists; attach it again.');

            return ['mime' => $file['mime'], 'data' => base64_encode($this->disk()->get($file['path']))];
        }, array_values($ids));
    }

    /** One of MIME_TYPES, from the file's own bytes. */
    public static function detectMime(string $bytes): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: null;

        return isset(self::MIME_TYPES[$mime]) ? $mime : null;
    }

    public static function supportsVision(?AiGatewayModel $model): bool
    {
        return in_array('vision', $model?->capabilities ?? [], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public static function hasImages(array $messages): bool
    {
        foreach ($messages as $message) {
            if (! empty($message['images'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ready messages for one model: a vision model keeps the images and no
     * OCR runs at all; for any other model each image is replaced by the text
     * OCR reads from it.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    public function prepareForModel(array $messages, ?AiGatewayModel $model): array
    {
        if (! self::hasImages($messages) || self::supportsVision($model)) {
            return $messages;
        }

        return array_map(function (array $message): array {
            if (empty($message['images'])) {
                return $message;
            }

            $notes = [];
            foreach (array_values($message['images']) as $index => $image) {
                $text = $this->ocr((string) ($image['data'] ?? ''));
                $label = 'Image '.($index + 1);
                $notes[] = $text !== null && $text !== ''
                    ? "[{$label}: this model cannot see images. Text read from it by OCR:]\n{$text}"
                    : "[{$label}: this model cannot see images, and OCR found no readable text in it.]";
            }

            unset($message['images']);
            $message['content'] = trim(((string) ($message['content'] ?? ''))."\n\n".implode("\n\n", $notes));

            return $message;
        }, $messages);
    }

    /**
     * Messages for the request log: image data replaced by a short label.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    public static function withoutImageData(array $messages): array
    {
        return array_map(function (array $message): array {
            if (! empty($message['images'])) {
                $message['images'] = array_map(
                    fn (array $image): string => sprintf('[%s, %d KB]', $image['mime'] ?? 'image', (int) (strlen((string) ($image['data'] ?? '')) * 3 / 4 / 1024)),
                    $message['images'],
                );
            }

            return $message;
        }, $messages);
    }

    /**
     * Text in an image, via drust's OCR. Never throws: without OCR the model
     * is told the image could not be read.
     */
    private function ocr(string $base64): ?string
    {
        $key = sha1($base64);
        if (array_key_exists($key, $this->ocrCache)) {
            return $this->ocrCache[$key];
        }

        $url = (string) config('serverpanel.media_ocr_api_url');
        $text = null;

        if ($url !== '' && $base64 !== '') {
            try {
                $token = (string) config('serverpanel.execution_api_token');
                $response = Http::timeout(60)->acceptJson()
                    ->when($token !== '', fn ($request) => $request->withToken($token))
                    ->post($url, ['data' => $base64]);

                if ($response->successful() && $response->json('success')) {
                    $text = trim((string) $response->json('data.text'));
                } else {
                    Log::warning('AI Gateway: image OCR did not succeed', ['status' => $response->status(), 'body' => Str::limit($response->body(), 500)]);
                }
            } catch (\Throwable $e) {
                Log::warning('AI Gateway: image OCR failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->ocrCache[$key] = $text;
    }
}
