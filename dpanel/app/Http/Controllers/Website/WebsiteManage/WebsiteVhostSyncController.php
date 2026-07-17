<?php

namespace App\Http\Controllers\Website\WebsiteManage;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Website\WebsiteWebServerSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteVhostSyncController extends Controller
{
    public function __construct(
        protected WebsiteWebServerSyncService $webServerSyncService,
    ) {
    }

    public function sync(Request $request, string $token, string $id): JsonResponse
    {
        $website = Website::query()
            ->visibleTo($request->user())
            ->firstWhere('id', $id);

        if (! $website) {
            return response()->json([
                'type' => 'error',
                'message' => 'Website not found.',
            ], 404);
        }

        try {
            $syncResult = $this->webServerSyncService->syncWebsite($website);
        } catch (\Throwable $e) {
            return response()->json([
                'type' => 'error',
                'message' => 'Website live vhost sync failed.',
                'errors' => [
                    'vhost_sync' => $e->getMessage(),
                ],
                'website' => $website->fresh(),
            ], 422);
        }

        return response()->json([
            'type' => 'success',
            'message' => 'Live vhost synced successfully.',
            'sync_result' => $syncResult,
            'website' => $website->fresh(),
        ]);
    }
}
