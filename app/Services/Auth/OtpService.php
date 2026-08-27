<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public function generate(
        string $identifier,
        string $type,
        string $channel,
        ?int $userId = null,
        ?string $ipAddress = null,
    ): OtpCode {
        OtpCode::where('identifier', $identifier)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->update(['expires_at' => now()->subMinute()]);

        $code = $this->generateCode();
        $expiryMinutes = (int) config('auth.otp.expiry_minutes', 5);

        $otp = OtpCode::create([
            'user_id'    => $userId,
            'identifier' => $identifier,
            'code'       => $code,
            'type'       => $type,
            'channel'    => $channel,
            'expires_at' => now()->addMinutes($expiryMinutes),
            'ip_address' => $ipAddress,
        ]);

        if ($channel === 'email') {
            try {
                $html = $this->buildHtml($code, $type, $expiryMinutes);
                Mail::html($html, function ($msg) use ($identifier, $type) {
                    $label = ucwords(str_replace('_', ' ', $type));
                    $msg->to($identifier)->subject("SAFCO LMS — Nambari yako ya {$label}");
                });
            } catch (\Throwable $e) {
                Log::error('OTP email failed', [
                    'to'    => $identifier,
                    'type'  => $type,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return $otp;
    }

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

    protected function buildHtml(string $code, string $type, int $expiryMinutes): string
    {
        $label = ucwords(str_replace('_', ' ', $type));

        return <<<HTML
<!DOCTYPE html>
<html lang="sw">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 20px;">
    <tr><td align="center">
      <table width="540" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr>
          <td style="background:#1e3a5f;padding:28px 40px;text-align:center;">
            <h1 style="margin:0;color:#f97316;font-size:22px;font-weight:900;letter-spacing:1px;">SAFCO FINTECH LMS</h1>
            <p style="margin:6px 0 0;color:#94a3b8;font-size:13px;">Tanzania's Leading Training Platform</p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:40px;">
            <h2 style="margin:0 0 8px;color:#1e293b;font-size:20px;">Nambari yako ya Uthibitisho</h2>
            <p style="margin:0 0 28px;color:#64748b;font-size:14px;line-height:1.6;">
              Tumepokea ombi lako la <strong>{$label}</strong>. Tumia nambari hii kukamilisha hatua yako:
            </p>

            <!-- Code box -->
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="background:#fff7ed;border:2px dashed #f97316;border-radius:12px;padding:28px;">
                  <span style="font-size:48px;font-weight:900;letter-spacing:12px;color:#f97316;font-family:monospace;">{$code}</span>
                </td>
              </tr>
            </table>

            <p style="margin:24px 0 0;color:#64748b;font-size:14px;text-align:center;">
              ⏱ Nambari hii itakuwa batili baada ya <strong>{$expiryMinutes} dakika</strong>.
            </p>

            <hr style="border:none;border-top:1px solid #e2e8f0;margin:32px 0;">

            <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.6;">
              Kama hukuomba nambari hii, puuza barua pepe hii. Usishirikishe nambari hii na mtu yeyote.
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 40px;text-align:center;">
            <p style="margin:0;color:#94a3b8;font-size:12px;">
              © 2026 SAFCO FINTECH LTD · Dar es Salaam, Tanzania<br>
              Barua pepe hii imetumwa kiotomatiki — usjibu.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
