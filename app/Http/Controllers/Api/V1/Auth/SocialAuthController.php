<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\JsonResponse;
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
     * Handles the OAuth callback and issues a Sanctum token.
     */
    public function callback(string $provider, Request $request): JsonResponse
    {
        $result = $this->social->handleCallback(
            $provider,
            $request->query('device_name', 'social-web')
        );

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'is_new_user' => $result['is_new_user'],
        ], 'Social login successful');
    }
}
