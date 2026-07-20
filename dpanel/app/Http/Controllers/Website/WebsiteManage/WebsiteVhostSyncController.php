<?php

namespace App\Http\Controllers\Website\WebsiteManage;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Website\WebsiteWebServerSyncService;
use App\Services\Ssl\SslLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteVhostSyncController extends Controller
{
    public function __construct(
        protected WebsiteWebServerSyncService $webServerSyncService,
        protected SslLifecycleService $sslLifecycleService,
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
            $sslResult = $this->sslLifecycleService->ensureForWebsite($website);
            if ((bool) $website->enable_ssl && ($sslResult['status'] ?? '') === 'valid') {
                $syncResult = $this->webServerSyncService->syncWebsite($website);
            }
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
            'ssl_result' => $sslResult,
            'website' => $website->fresh(),
        ]);
    }
}
