<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialAuthController extends Controller
{
    public function __construct(protected SocialAuthService $social)
    {
    }

    /**
     * GET /api/v1/auth/social/{provider}
     * Returns the OAuth redirect URL for the given provider.
     */
    public function redirect(string $provider): JsonResponse
    {
        return $this->success([
            'redirect_url' => $this->social->redirectUrl($provider),
        ]);
    }

    /**
     * GET /api/v1/auth/social/{provider}/callback
     * Handles the OAuth callback. On success, redirects the browser to the
     * frontend /auth/callback page with the token in the URL fragment so the
     * SPA can store it without the token ever appearing in server logs.
     */
    public function callback(string $provider, Request $request): RedirectResponse
    {
        $frontendBase = rtrim((string) env('FRONTEND_URL', 'http://localhost:3002'), '/');

        try {
            $result = $this->social->handleCallback($provider, 'social-web');

            $user     = new UserResource($result['user']);
            $userData = json_encode($user->resolve(), JSON_UNESCAPED_UNICODE);

            $params = http_build_query([
                'token'      => $result['token'],
                'expires_at' => $result['expires_at'] ?? '',
                'is_new'     => $result['is_new_user'] ? '1' : '0',
                'user'       => base64_encode($userData),
            ]);

            return redirect("{$frontendBase}/auth/callback?{$params}");
        } catch (\Throwable $e) {
            return redirect("{$frontendBase}/login?error=" . urlencode($e->getMessage()));
        }
    }
}
