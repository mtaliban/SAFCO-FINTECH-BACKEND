<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentProvider;
use App\Services\Payment\Providers\AirtelMoneyProvider;
use App\Services\Payment\Providers\CardProvider;
use App\Services\Payment\Providers\CrdbProvider;
use App\Services\Payment\Providers\MixxProvider;
use App\Services\Payment\Providers\MpesaProvider;
use App\Services\Payment\Providers\NbcProvider;
use App\Services\Payment\Providers\NmbProvider;
use InvalidArgumentException;

/**
 * SRS Module 12 — provider registry / factory.
 * Single source of truth for the 7 SRS-mandated providers.
 */
class PaymentProviderRegistry
{
    /** @var array<string, PaymentProvider> */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            Payment::PROVIDER_MPESA        => new MpesaProvider(),
            Payment::PROVIDER_MIXX         => new MixxProvider(),
            Payment::PROVIDER_AIRTEL_MONEY => new AirtelMoneyProvider(),
            Payment::PROVIDER_CRDB         => new CrdbProvider(),
            Payment::PROVIDER_NMB          => new NmbProvider(),
            Payment::PROVIDER_NBC          => new NbcProvider(),
            Payment::PROVIDER_CARD_VISA    => new CardProvider('visa'),
            Payment::PROVIDER_CARD_MC      => new CardProvider('mastercard'),
        ];
    }

    public function get(string $code): PaymentProvider
    {
        if (!isset($this->providers[$code])) {
            throw new InvalidArgumentException("Unknown payment provider: $code");
        }
        return $this->providers[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->providers[$code]);
    }

    /** @return array<int, array{code:string, name:string, category:string}> */
    public function catalog(): array
    {
        return array_map(fn (PaymentProvider $p) => [
            'code' => $p->code(),
            'name' => $p->displayName(),
            'category' => $p->category(),
        ], array_values($this->providers));
    }
}
