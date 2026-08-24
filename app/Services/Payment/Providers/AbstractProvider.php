<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentProvider;
use App\Services\Payment\DTOs\InitiationRequest;
use App\Services\Payment\DTOs\InitiationResult;
use App\Services\Payment\DTOs\WebhookResult;
use Illuminate\Support\Str;

/**
 * SRS Module 12 — shared behavior for all providers.
 *
 * Real providers subclass this and override initiate/handleWebhook.
 * In non-production environments the base class provides a MOCK flow:
 *   - initiate() returns accepted with a fake provider_ref
 *   - handleWebhook() reads a JSON body {provider_ref, status} and treats it as final
 *
 * This lets us test the full pipeline end-to-end without hitting live
 * mobile-money sandboxes, while keeping the production seat clearly labeled.
 */
abstract class AbstractProvider implements PaymentProvider
{
    protected function isMockMode(): bool
    {
        return (bool) config('payments.mock_mode', true);
    }

    /**
     * Verify HMAC signature. Override per provider with real algorithm.
     * Default: compare X-Safco-Signature header to hex-hmac-sha256(rawBody, secret).
     */
    protected function verifySignature(array $headers, string $rawBody, string $secret): bool
    {
        // In mock mode we accept everything (but tests still exercise real verification).
        if ($this->isMockMode() && empty($secret)) {
            return true;
        }

        $received = $headers['x-safco-signature'][0]
            ?? $headers['X-Safco-Signature'][0]
            ?? ($headers['x-safco-signature'] ?? null)
            ?? ($headers['X-Safco-Signature'] ?? null);

        if (!$received || !$secret) return false;

        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, is_array($received) ? $received[0] : $received);
    }

    /**
     * Default mock implementation used by unit-tests. Real providers override.
     */
    public function initiate(Payment $payment, InitiationRequest $req): InitiationResult
    {
        if (!$this->isMockMode()) {
            return InitiationResult::rejected(
                'not_configured',
                sprintf('Provider %s is not configured for live mode.', $this->code())
            );
        }
        return InitiationResult::acceptedStkPush(
            providerRef: 'MOCK-' . Str::upper(Str::random(10)),
            instruction: sprintf('MOCK %s payment — call webhook to complete.', $this->displayName()),
            meta: ['mock' => true, 'msisdn' => $req->msisdn],
        );
    }

    /**
     * Default mock webhook implementation. Real providers override with
     * signature verification + provider-specific payload parsing.
     */
    public function handleWebhook(array $payload, array $headers, string $rawBody): WebhookResult
    {
        $providerRef = (string) ($payload['provider_ref'] ?? '');
        $status = (string) ($payload['status'] ?? Payment::STATUS_FAILED);

        $ok = in_array($status, [
            Payment::STATUS_SUCCEEDED, Payment::STATUS_FAILED,
            Payment::STATUS_CANCELLED, Payment::STATUS_EXPIRED,
        ], true);

        return new WebhookResult(
            providerRef: $providerRef,
            status: $ok ? $status : Payment::STATUS_FAILED,
            amountTzs: isset($payload['amount_tzs']) ? (int) $payload['amount_tzs'] : null,
            currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
            failureCode: $payload['failure_code'] ?? null,
            failureMessage: $payload['failure_message'] ?? null,
            signatureVerified: $this->verifySignature($headers, $rawBody, (string) config("payments.providers.{$this->code()}.webhook_secret", '')),
            meta: $payload,
        );
    }

    public function queryStatus(Payment $payment): ?WebhookResult
    {
        // Not all providers support poll; default is null.
        return null;
    }
}
