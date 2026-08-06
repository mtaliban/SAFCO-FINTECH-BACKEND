<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures every incoming request carries an X-Correlation-ID header.
 * All log lines and events emitted during the request will share this ID,
 * enabling end-to-end tracing across services, brokers, and workers.
 */
class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID') ?: (string) Str::uuid();
        $request->headers->set('X-Correlation-ID', $correlationId);

        Log::withContext([
            'correlation_id' => $correlationId,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
