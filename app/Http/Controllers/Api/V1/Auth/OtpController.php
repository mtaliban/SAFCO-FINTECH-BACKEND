<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Events\User\EmailVerified;
use App\Events\User\PhoneVerified;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\EventBus\EventDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OtpController extends Controller
{
    public function __construct(
        protected OtpService $otp,
        protected EventDispatcher $events,
    ) {
    }

    /**
     * POST /api/v1/auth/otp/request
     * Generates and sends a new OTP.
     */
    public function request(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
            'type' => ['required', 'in:registration,login,password_reset,2fa,phone_verify,email_verify'],
            'channel' => ['required', 'in:sms,email'],
        ]);

        $user = User::where('email', $request->identifier)
            ->orWhere('phone', $request->identifier)
            ->first();

        $this->otp->generate(
            identifier: $request->identifier,
            type: $request->type,
            channel: $request->channel,
            userId: $user?->id,
            ipAddress: $request->ip(),
        );

        return $this->success(null, "OTP sent via {$request->channel}.");
    }

    /**
     * POST /api/v1/auth/otp/verify
     * Verifies an OTP code.
     */
    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $valid = $this->otp->verify(
            $request->identifier,
            $request->code,
            $request->type,
        );

        if (! $valid) {
            return $this->error('Invalid or expired OTP.', 422);
        }

        // Auto-mark email/phone as verified when relevant
        $user = User::where('email', $request->identifier)
            ->orWhere('phone', $request->identifier)
            ->first();

        if ($user) {
            if ($request->type === 'email_verify' && ! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                $user->update(['status' => 'active']);
                $this->events->dispatch(new EmailVerified($user), User::class, $user->id);
            }

            if ($request->type === 'phone_verify' && ! $user->hasVerifiedPhone()) {
                $user->markPhoneAsVerified();
                $this->events->dispatch(new PhoneVerified($user), User::class, $user->id);
            }
        }

        return $this->success(null, 'OTP verified successfully.');
    }
}
