<?php

namespace App\Http\Middleware;

use App\Support\Platform\PlatformCapabilities;
use Closure;
use Illuminate\Http\Request;

/** A feature this system does not have is a page that does not exist. */
class RequireCapability
{
    public function __construct(private readonly PlatformCapabilities $capabilities)
    {
    }

    public function handle(Request $request, Closure $next, string $capability)
    {
        abort_unless($this->capabilities->allows($capability), 404);

        return $next($request);
    }
}
