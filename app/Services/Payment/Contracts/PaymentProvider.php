<?php

namespace App\Services\Payment\Contracts;

use App\Models\Payment;
use App\Services\Payment\DTOs\InitiationRequest;
use App\Services\Payment\DTOs\InitiationResult;
use App\Services\Payment\DTOs\WebhookResult;

/**
 * SRS Module 12 — every payment provider (mobile money, bank, card)
 * implements this contract. The service layer is provider-agnostic.
 */
interface PaymentProvider
{
    /** Provider code as stored in Payment::provider. */
    public function code(): string;

    /** Human display name (e.g. "M-Pesa", "Mixx by Yas"). */
    public function displayName(): string;

    /** 'mobile_money' | 'bank' | 'card' */
    public function category(): string;

    /**
     * Initiate the payment against the provider.
     * MUST be idempotent — providers are called with a stable idempotency_key.
     */
    public function initiate(Payment $payment, InitiationRequest $req): InitiationResult;

    /**
     * Handle an inbound webhook / callback.
     * Verifies signature, decodes payload, returns the derived status.
     * MUST return the same result on retry (idempotency handled at service level).
     */
    public function handleWebhook(array $payload, array $headers, string $rawBody): WebhookResult;

    /**
     * Optional: poll provider for status (used when callbacks are unreliable).
     * Providers that don't support this may return null.
     */
    public function queryStatus(Payment $payment): ?WebhookResult;
}
