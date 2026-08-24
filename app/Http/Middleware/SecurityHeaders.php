<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * SRS Non-Functional Requirements — Security headers.
 *
 * Adds OWASP-recommended response headers on every request:
 *  - Strict-Transport-Security (HSTS): browser sticks to HTTPS for 1 year
 *  - X-Content-Type-Options: nosniff (prevent MIME-sniff attacks)
 *  - X-Frame-Options: DENY (clickjacking protection)
 *  - Referrer-Policy: strict-origin-when-cross-origin
 *  - Permissions-Policy: disable camera/mic/geo unless explicitly needed
 *  - X-XSS-Protection: 0 (old header — off is now safest per OWASP)
 *
 * HSTS is only added when the current request is HTTPS to avoid pinning
 * localhost dev to https.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (!method_exists($response, 'headers')) return $response;

        // NFR AUDIT-H: only set a header if the upstream (Nginx) hasn't already
        // set it — prevents duplicate headers with different values when both
        // layers try to inject the same one.
        $setIfMissing = function (string $key, string $value) use ($response) {
            if (!$response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        };

        $setIfMissing('X-Content-Type-Options', 'nosniff');
        $setIfMissing('X-Frame-Options', 'DENY');
        $setIfMissing('Referrer-Policy', 'strict-origin-when-cross-origin');
        $setIfMissing('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');
        $setIfMissing('X-XSS-Protection', '0');

        if ($request->isSecure()) {
            $setIfMissing('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        return $response;
    }
}
