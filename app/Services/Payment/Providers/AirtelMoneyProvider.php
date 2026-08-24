<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;

/**
 * Airtel Money Tanzania — via Airtel Open API v3 (collection).
 */
class AirtelMoneyProvider extends AbstractProvider
{
    public function code(): string { return Payment::PROVIDER_AIRTEL_MONEY; }
    public function displayName(): string { return 'Airtel Money'; }
    public function category(): string { return 'mobile_money'; }
}
