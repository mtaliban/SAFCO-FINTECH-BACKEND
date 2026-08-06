<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->isLocked()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is locked. Try again later.',
                'locked_until' => $user->locked_until?->toIso8601String(),
            ], 423);
        }

        if (! in_array($user->status, ['active', 'pending'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Your account is {$user->status}.",
            ], 403);
        }

        return $next($request);
    }
}
