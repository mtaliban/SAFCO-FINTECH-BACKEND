<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactorService $twoFactor)
    {
    }

    /**
     * POST /api/v1/auth/2fa/setup
     * Starts TOTP setup — returns QR code SVG for Google Authenticator.
     */
    public function setup(Request $request): JsonResponse
    {
        $data = $this->twoFactor->setupTotp($request->user());

        return $this->success([
            'secret' => $data['secret'],
            'qr_code_svg' => $data['qr_code_svg'],
            'otpauth_url' => $data['otpauth_url'],
            'instructions' => 'Scan the QR code with Google Authenticator, then confirm with a 6-digit code.',
        ]);
    }

    /**
     * POST /api/v1/auth/2fa/confirm
     * Confirms the TOTP setup and enables 2FA.
     */
    public function confirm(TwoFactorConfirmRequest $request): JsonResponse
    {
        try {
            $recoveryCodes = $this->twoFactor->confirmTotp($request->user(), $request->code);
        } catch (\InvalidArgumentException) {
            return $this->error('Invalid 2FA code.', 422);
        }

        return $this->success([
            'enabled' => true,
            'recovery_codes' => $recoveryCodes,
            'warning' => 'Store these recovery codes safely. Each can only be used once.',
        ], 'Two-factor authentication enabled.');
    }

    /**
     * POST /api/v1/auth/2fa/challenge
     * Second-factor step during login.
     */
    public function challenge(TwoFactorConfirmRequest $request): JsonResponse
    {
        $ok = $this->twoFactor->verifyTotp($request->user(), $request->code);

        return $ok
            ? $this->success(['verified' => true], '2FA verified.')
            : $this->error('Invalid 2FA code.', 422);
    }

    /**
     * DELETE /api/v1/auth/2fa
     * Disables 2FA on the current account.
     */
    public function disable(Request $request): JsonResponse
    {
        $this->twoFactor->disable($request->user());

        return $this->success(null, 'Two-factor authentication disabled.');
    }
}
