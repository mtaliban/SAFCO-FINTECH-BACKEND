<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;

/**
 * M-Pesa (Vodacom Tanzania) — STK push via Daraja/OpenAPI.
 *
 * Real API (when config('payments.mock_mode') === false):
 *   POST https://openapi.m-pesa.com/openapi/ipg/v2/vodacomTZN/c2bPayment/
 *   with OAuth bearer, callback signature via 'X-Signature'.
 *
 * Mock mode routes through AbstractProvider for pipeline testing.
 */
class MpesaProvider extends AbstractProvider
{
    public function code(): string { return Payment::PROVIDER_MPESA; }
    public function displayName(): string { return 'M-Pesa'; }
    public function category(): string { return 'mobile_money'; }
}
