<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(protected PasswordResetService $passwordReset)
    {
    }

    /**
     * POST /api/v1/auth/password/forgot
     * Requests a password reset email.
     * Always returns success to avoid user enumeration.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->request($request->email);

        return $this->success(
            null,
            'If your email exists in our system, a reset link has been sent.'
        );
    }

    /**
     * POST /api/v1/auth/password/reset
     * Consumes the reset token and sets the new password.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $ok = $this->passwordReset->reset(
            email: $request->email,
            token: $request->token,
            newPassword: $request->password,
        );

        if (! $ok) {
            return $this->error('Invalid or expired reset token.', 422);
        }

        return $this->success(null, 'Password reset successful. Please log in.');
    }
}
