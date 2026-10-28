<?php

namespace App\Http\Controllers;

use App\Services\AiGateway\AiGatewayService;
use App\Services\AiGateway\GatewayMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Images in the AI Gateway chat: uploads people attach to a message, images
 * an image model generates, and serving both back to the page.
 */
class AiGatewayMediaController extends Controller
{
    public const IMAGE_SIZES = ['auto', '1024x1024', '1024x1536', '1536x1024', '1792x1024', '1024x1792', '512x512', '256x256'];

    public function __construct(
        private readonly GatewayMedia $media,
        private readonly AiGatewayService $gateway,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'max:'.(GatewayMedia::MAX_BYTES / 1024), 'mimetypes:'.implode(',', array_keys(GatewayMedia::MIME_TYPES))],
        ]);

        $bytes = (string) file_get_contents($request->file('image')->getRealPath());
        $mime = GatewayMedia::detectMime($bytes);
        if ($mime === null) {
            return response()->json(['message' => 'Images must be PNG, JPEG, WebP or GIF.'], 422);
        }

        $stored = $this->media->store($request->user()->id, $bytes, $mime);

        return response()->json([...$stored, 'owner' => $request->user()->id]);
    }

    public function show(Request $request, $token, string $media): Response
    {
        $user = $request->user();
        $ownerId = (int) ($request->query('owner') ?: $user->id);

        // Same rule as chat history: admins may open other people's chats.
        if ($ownerId !== $user->id && ! $user->hasRole('admin')) {
            abort(403);
        }

        $file = $this->media->find($ownerId, $media) ?? abort(404);

        return response()->file(Storage::disk('local')->path($file['path']), [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'model' => ['nullable', 'string', 'max:255'],
            'provider_id' => ['nullable', 'integer'],
            'size' => ['nullable', Rule::in(self::IMAGE_SIZES)],
            'n' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        try {
            $result = $this->gateway->generateImage($data['model'] ?? null, $data['prompt'], [
                'size' => ($data['size'] ?? 'auto') === 'auto' ? null : $data['size'],
                'n' => $data['n'] ?? 1,
                'channel' => 'playground',
                'created_by' => $request->user()->id,
            ], $data['provider_id'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $images = array_map(
            fn (array $image): array => [
                ...$this->media->store($request->user()->id, (string) base64_decode($image['data']), GatewayMedia::detectMime((string) base64_decode($image['data'])) ?? $image['mime']),
                'owner' => $request->user()->id,
            ],
            $result['images'],
        );

        return response()->json([
            'ok' => true,
            'images' => $images,
            'text' => $result['text'],
            'model' => $result['model'],
            'provider_name' => $result['provider']->name,
        ]);
    }
}
