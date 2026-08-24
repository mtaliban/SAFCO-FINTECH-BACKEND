<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __construct(
        protected AuthService $auth,
        protected OtpService $otp,
        protected NotificationDispatcher $notifications,
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

        $expiryMinutes = (int) (config('sanctum.expiration') ?: 1440);
        $token = $user->createToken('web', ['*'], now()->addMinutes($expiryMinutes));

        $this->notifications->dispatch($user, 'account.welcome', [
            'action_url' => config('app.url') . '/dashboard',
            'action_label' => 'Ingia dashboard',
        ]);

        return $this->success(
            data: [
                'user'  => new UserResource($user),
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'requires_verification' => false,
            ],
            message: 'Registration successful.',
            status: 201
        );
    }
}
