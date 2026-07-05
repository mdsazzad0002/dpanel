<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ApplyPanelRouteDefaults
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->session()->get('panel_session_token', '');

        if ($token !== '') {
            URL::defaults(['token' => $token]);
        }

        return $next($request);
    }
}
