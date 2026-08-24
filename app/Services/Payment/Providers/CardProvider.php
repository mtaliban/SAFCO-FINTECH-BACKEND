<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;
use App\Services\Payment\DTOs\InitiationRequest;
use App\Services\Payment\DTOs\InitiationResult;

/**
 * Card acquirer (Visa/Mastercard) via DPO Group / Flutterwave / Stripe TZS.
 * Uses a redirect flow — user leaves for hosted checkout, returns to returnUrl.
 */
class CardProvider extends AbstractProvider
{
    public function __construct(private readonly string $brand) {}

    public function code(): string
    {
        return $this->brand === 'mastercard'
            ? Payment::PROVIDER_CARD_MC
            : Payment::PROVIDER_CARD_VISA;
    }

    public function displayName(): string
    {
        return $this->brand === 'mastercard' ? 'Mastercard' : 'Visa';
    }

    public function category(): string { return 'card'; }

    public function initiate(Payment $payment, InitiationRequest $req): InitiationResult
    {
        if (!$this->isMockMode()) {
            return InitiationResult::rejected('not_configured', 'Card provider not configured for live mode.');
        }
        // Mock: return a fake hosted-checkout URL under our own domain.
        return InitiationResult::acceptedRedirect(
            providerRef: 'MOCK-CARD-' . $payment->uuid,
            checkoutUrl: url("/mock-checkout/{$payment->uuid}"),
            meta: ['mock' => true, 'brand' => $this->brand],
        );
    }
}
