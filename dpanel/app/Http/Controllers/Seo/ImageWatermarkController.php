<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Services\Seo\ImageWatermarkInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SEO Tools → Image Watermark. The check reads the file's metadata here;
 * removing a visible mark is done in the browser on a canvas.
 */
class ImageWatermarkController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Seo/ImageWatermark', [
            'maxBytes' => ImageWatermarkInspector::MAX_FILE_BYTES,
        ]);
    }

    public function check(Request $request, string $token, ImageWatermarkInspector $inspector): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'max:'.intdiv(ImageWatermarkInspector::MAX_FILE_BYTES, 1024)],
        ]);

        try {
            $report = $inspector->inspect((string) $request->file('image')->getRealPath());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['image' => [$exception->getMessage()]]], 422);
        }

        return response()->json($report);
    }
}
