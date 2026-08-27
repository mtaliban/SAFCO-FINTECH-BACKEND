<?php

namespace App\Services\Auth;

use App\Events\User\OtpRequested;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Event;

class OtpService
{
    /**
     * Generate an OTP code, store it, and dispatch an event so
     * downstream workers (SMS/Email) actually deliver it.
     *
     * Uses Laravel's built-in event system (not the outbox EventDispatcher)
     * because OTP delivery is time-sensitive and must fire immediately
     * via the queued SendOtpNotification listener.
     */
    public function generate(
        string $identifier,
        string $type,
        string $channel,
        ?int $userId = null,
        ?string $ipAddress = null,
    ): OtpCode {
        // Invalidate previous unverified codes of the same type
        OtpCode::where('identifier', $identifier)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->update(['expires_at' => now()->subMinute()]);

        $code = $this->generateCode();
        $expiryMinutes = (int) config('auth.otp.expiry_minutes', 5);

        $otp = OtpCode::create([
            'user_id' => $userId,
            'identifier' => $identifier,
            'code' => $code,
            'type' => $type,
            'channel' => $channel,
            'expires_at' => now()->addMinutes($expiryMinutes),
            'ip_address' => $ipAddress,
        ]);

        Event::dispatch(new OtpRequested(
            identifier: $identifier,
            code: $code,
            type: $type,
            channel: $channel,
            userId: $userId,
        ));

        return $otp;
    }

    /**
     * Verify a submitted OTP code.
     * Returns true when the code matches, is not expired, and has not
     * exceeded the max attempts count.
     */
    public function verify(string $identifier, string $code, string $type): bool
    {
        $otp = OtpCode::where('identifier', $identifier)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->latest()
            ->first();

        if (! $otp) {
            return false;
        }

        if ($otp->isExpired()) {
            return false;
        }

        if ($otp->hasExceededAttempts()) {
            return false;
        }

        $otp->increment('attempts');

        if (! hash_equals((string) $otp->code, (string) $code)) {
            return false;
        }

        $otp->update(['verified_at' => now()]);

        return true;
    }

    protected function generateCode(): string
    {
        $length = (int) config('auth.otp.length', 6);
        $min = (int) str_pad('1', $length, '0');
        $max = (int) str_pad('9', $length, '9');

        return (string) random_int($min, $max);
    }
}
