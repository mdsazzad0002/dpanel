<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Services\Seo\PreviewProxy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the URL Inspection Preview and everything it loads. The route is
 * guarded by PreviewProxy's signed origin token rather than the panel session:
 * the preview frame has an opaque origin, so its requests carry no cookies.
 */
class PreviewProxyController extends Controller
{
    public function show(Request $request, PreviewProxy $proxy, string $token): Response
    {
        // The raw request URI keeps the site's own encoding and query order.
        $uri = $request->getRequestUri();
        $target = $proxy->target($token, substr($uri, strpos($uri, $token) + strlen($token)));
        abort_unless($target !== null, 403);

        $response = $proxy->fetch($target['url'], $target['agent'], $target['local']);
        if ($response['location'] !== null) {
            return response('', $response['status'], ['Location' => $response['location'], 'Referrer-Policy' => 'no-referrer']);
        }

        return response($response['status'] > 0 ? $response['body'] : ($response['error'] ?? 'No response'), $response['status'] ?: 502, [
            'Content-Type' => $response['status'] > 0 && $response['type'] !== '' ? $response['type'] : 'text/plain; charset=UTF-8',
            // Opened on its own, a proxied page still runs in an opaque origin, never the panel's.
            'Content-Security-Policy' => 'sandbox allow-scripts allow-popups allow-forms',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            // Fonts and crossorigin scripts are requested from the opaque origin.
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
