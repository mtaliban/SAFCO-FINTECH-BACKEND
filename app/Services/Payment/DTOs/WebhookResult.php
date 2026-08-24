<?php

namespace App\Services\Payment\DTOs;

use App\Models\Payment;

/**
 * Provider-agnostic result of parsing a webhook or status query.
 */
final class WebhookResult
{
    public function __construct(
        public readonly string $providerRef,   // used to look up the local Payment row
        public readonly string $status,        // one of Payment::STATUS_*
        public readonly ?int $amountTzs = null, // provider-reported amount (validated at service layer)
        public readonly ?string $currency = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly bool $signatureVerified = false,
        public readonly array $meta = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === Payment::STATUS_SUCCEEDED;
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, [
            Payment::STATUS_SUCCEEDED, Payment::STATUS_FAILED,
            Payment::STATUS_CANCELLED, Payment::STATUS_EXPIRED, Payment::STATUS_REVERSED,
        ], true);
    }
}
