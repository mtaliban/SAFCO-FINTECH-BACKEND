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
     * Step 1: validate credentials, then send OTP to email.
     * Returns {otp_sent: true, email: "..."} when OTP is dispatched,
     * or a full token when the user has no email (phone-only).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->validated(), $request);

        if (isset($result['otp_sent']) && $result['otp_sent']) {
            return $this->success([
                'otp_sent' => true,
                'email'    => $result['email'],
            ], 'Nambari ya uthibitisho imetumwa kwa barua pepe yako.');
        }

        return $this->success([
            'user'         => new UserResource($result['user']),
            'token'        => $result['token'],
            'token_type'   => $result['token_type'],
            'expires_at'   => $result['expires_at'],
            'requires_2fa' => false,
        ], 'Login successful');
    }

    /**
     * POST /api/v1/auth/login/verify
     * Step 2: verify the OTP code, issue Sanctum token.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email'       => ['required', 'email'],
            'code'        => ['required', 'string', 'size:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->auth->verifyLoginOtp(
            email: $request->email,
            code: $request->code,
            deviceName: $request->device_name ?? 'web',
            request: $request,
        );

        return $this->success([
            'user'         => new UserResource($result['user']),
            'token'        => $result['token'],
            'token_type'   => $result['token_type'],
            'expires_at'   => $result['expires_at'],
            'requires_2fa' => false,
        ], 'Login successful');
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user(), $request);

        return $this->success(null, 'Logged out successfully');
    }

    /**
     * GET /api/v1/auth/me
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
