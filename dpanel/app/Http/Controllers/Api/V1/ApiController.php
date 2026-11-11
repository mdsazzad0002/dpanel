<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared parts of the public API (backlog U1). Where the panel already does
 * the work in a controller, the API hands it a request of its own instead of
 * repeating the logic, so both check and do exactly the same.
 */
abstract class ApiController extends Controller
{
    /** A JSON request to a panel action, made by the same user as `$request`. */
    protected function panelRequest(Request $request, array $input, string $method = 'POST'): Request
    {
        $panel = Request::create('/'.$request->path(), $method, $input, [], [], ['REMOTE_ADDR' => $request->ip()]);
        $panel->headers->set('Accept', 'application/json');
        $panel->setUserResolver($request->getUserResolver());

        return $panel;
    }

    /** A failed panel JSON response in the API's error shape: `{message, errors?}`. */
    protected function panelError(JsonResponse $response): JsonResponse
    {
        $data = (array) $response->getData(true);
        $body = ['message' => (string) ($data['message'] ?? 'The request failed.')];
        if (! empty($data['errors'])) {
            $body['errors'] = array_map(fn ($value) => array_values((array) $value), (array) $data['errors']);
        }
        $status = $response->getStatusCode();

        return response()->json($body, $status >= 400 ? $status : 422);
    }

    protected function notFound(string $what): JsonResponse
    {
        return response()->json(['message' => "{$what} not found."], 404);
    }
}
