<?php

namespace App\Services\Payment\DTOs;

/**
 * Result of PaymentProvider::initiate().
 */
final class InitiationResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly string $providerRef,       // provider-side id (STK push id / transaction id)
        public readonly ?string $checkoutUrl = null, // for card / bank redirect flows
        public readonly ?string $userInstruction = null, // e.g. "Check your phone for the M-Pesa prompt"
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly array $meta = [],
    ) {}

    public static function acceptedStkPush(string $providerRef, string $instruction, array $meta = []): self
    {
        return new self(
            accepted: true,
            providerRef: $providerRef,
            userInstruction: $instruction,
            meta: $meta,
        );
    }

    public static function acceptedRedirect(string $providerRef, string $checkoutUrl, array $meta = []): self
    {
        return new self(
            accepted: true,
            providerRef: $providerRef,
            checkoutUrl: $checkoutUrl,
            meta: $meta,
        );
    }

    public static function rejected(string $code, string $message): self
    {
        return new self(
            accepted: false,
            providerRef: '',
            failureCode: $code,
            failureMessage: $message,
        );
    }
}
