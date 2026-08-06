<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __construct(
        protected AuthService $auth,
        protected OtpService $otp,
    ) {
    }

    /**
     * POST /api/v1/auth/register
     * Registers a new user, dispatches UserRegistered event,
     * and sends an email verification OTP.
     */
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated());

        $this->otp->generate(
            identifier: $user->email,
            type: 'email_verify',
            channel: 'email',
            userId: $user->id,
            ipAddress: $request->ip(),
        );

        return $this->success(
            data: [
                'user' => new UserResource($user),
                'requires_verification' => true,
                'verification_channel' => 'email',
            ],
            message: 'Registration successful. Please verify your email.',
            status: 201
        );
    }
}
