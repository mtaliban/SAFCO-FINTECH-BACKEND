<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks IPs flagged by the DetectSuspiciousLogin listener.
 */
class BlockIpMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Cache::has("blocked_ip:{$request->ip()}")) {
            return response()->json([
                'success' => false,
                'message' => 'Your IP address has been temporarily blocked due to suspicious activity.',
            ], 429);
        }

        return $next($request);
    }
}
