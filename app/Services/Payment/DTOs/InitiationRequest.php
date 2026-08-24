<?php

namespace App\Services\Payment\DTOs;

/**
 * Immutable payload passed into PaymentProvider::initiate().
 * Carries the payer's identity + instrument (msisdn / card token / bank ref).
 */
final class InitiationRequest
{
    public function __construct(
        public readonly int $amountTzs,
        public readonly string $description,
        public readonly string $callbackUrl,       // where the provider POSTs the outcome
        public readonly string $returnUrl,         // where the user's browser lands after redirect
        public readonly ?string $msisdn = null,    // 2557XXXXXXXX for mobile money
        public readonly ?string $cardToken = null, // tokenised card ref (never PAN)
        public readonly ?string $bankRef = null,   // for bank pull payments
        public readonly ?string $payerName = null,
        public readonly ?string $payerEmail = null,
    ) {}
}
