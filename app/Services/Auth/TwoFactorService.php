<?php

namespace App\Services\Auth;

use App\Events\User\TwoFactorDisabled;
use App\Events\User\TwoFactorEnabled;
use App\Models\TwoFactorAuth;
use App\Models\User;
use App\Services\EventBus\EventDispatcher;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Handles Time-based One-Time Password (TOTP) 2FA using Google Authenticator.
 * Also SMS-based 2FA is delegated to OtpService.
 */
class TwoFactorService
{
    protected Google2FA $google2fa;

    public function __construct(
        protected EventDispatcher $events,
        protected OtpService $otp,
    ) {
        $this->google2fa = new Google2FA();
    }

    /**
     * Start TOTP setup: generate a secret + QR code SVG.
     * User must scan the QR in Google Authenticator, then call `confirm`
     * with a code from the app to actually enable 2FA.
     */
    public function setupTotp(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        $twoFactor = TwoFactorAuth::updateOrCreate(
            ['user_id' => $user->id, 'method' => 'totp'],
            [
                'secret' => $secret,
                'enabled_at' => null,
            ]
        );

        $otpauthUrl = $this->google2fa->getQRCodeUrl(
            config('auth.two_factor.issuer', 'SAFCO FINTECH LMS'),
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(300),
            new SvgImageBackEnd()
        );
        $qrSvg = (new Writer($renderer))->writeString($otpauthUrl);

        return [
            'secret' => $secret,
            'qr_code_svg' => $qrSvg,
            'otpauth_url' => $otpauthUrl,
        ];
    }

    /**
     * Confirm TOTP setup by validating a code from the authenticator app.
     * On success: marks 2FA enabled, generates one-time recovery codes.
     */
    public function confirmTotp(User $user, string $code): array
    {
        $twoFactor = TwoFactorAuth::where('user_id', $user->id)
            ->where('method', 'totp')
            ->firstOrFail();

        if (! $this->google2fa->verifyKey($twoFactor->secret, $code)) {
            throw new \InvalidArgumentException('Invalid 2FA code');
        }

        return DB::transaction(function () use ($user, $twoFactor) {
            $recoveryCodes = collect(range(1, 8))
                ->map(fn () => Str::random(10))
                ->all();

            $twoFactor->update([
                'enabled_at' => now(),
                'recovery_codes' => $recoveryCodes,
            ]);

            $user->update([
                'two_factor_enabled' => true,
                'two_factor_method' => 'totp',
                'two_factor_verified_at' => now(),
            ]);

            $this->events->dispatch(
                new TwoFactorEnabled($user, 'totp'),
                aggregateType: User::class,
                aggregateId: $user->id
            );

            return $recoveryCodes;
        });
    }

    /**
     * Verify a TOTP code during login (challenge step).
     */
    public function verifyTotp(User $user, string $code): bool
    {
        $twoFactor = TwoFactorAuth::where('user_id', $user->id)
            ->where('method', 'totp')
            ->whereNotNull('enabled_at')
            ->first();

        if (! $twoFactor) {
            return false;
        }

        if ($this->google2fa->verifyKey($twoFactor->secret, $code)) {
            $twoFactor->update(['last_used_at' => now()]);
            return true;
        }

        // Also allow one-time recovery codes
        $codes = $twoFactor->recovery_codes ?? [];
        if (in_array($code, $codes, true)) {
            $twoFactor->update([
                'recovery_codes' => array_values(array_diff($codes, [$code])),
                'last_used_at' => now(),
            ]);
            return true;
        }

        return false;
    }

    public function disable(User $user): void
    {
        DB::transaction(function () use ($user) {
            $method = $user->two_factor_method ?? 'totp';

            TwoFactorAuth::where('user_id', $user->id)->delete();

            $user->update([
                'two_factor_enabled' => false,
                'two_factor_method' => null,
                'two_factor_verified_at' => null,
            ]);

            $this->events->dispatch(
                new TwoFactorDisabled($user, $method),
                aggregateType: User::class,
                aggregateId: $user->id
            );
        });
    }
}
