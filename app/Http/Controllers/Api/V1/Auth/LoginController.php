<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function __construct(protected AuthService $auth)
    {
    }

    /**
     * POST /api/v1/auth/login
     * Authenticates via email/phone + password.
     * Returns Sanctum token if credentials are valid.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->validated(), $request);

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_at' => $result['expires_at'],
            'requires_2fa' => $result['requires_2fa'],
        ], 'Login successful');
    }

    /**
     * POST /api/v1/auth/logout
     * Revokes the current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user(), $request);

        return $this->success(null, 'Logged out successfully');
    }

    /**
     * GET /api/v1/auth/me
     * Returns the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(
            new UserResource(
                $request->user()->load(['profile', 'organization', 'roles'])
            )
        );
    }
}
