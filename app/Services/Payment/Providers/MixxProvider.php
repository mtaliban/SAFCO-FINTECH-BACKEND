<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;

/**
 * Mixx by Yas (formerly Tigo Pesa) — via AzamPay or Selcom gateway aggregation.
 */
class MixxProvider extends AbstractProvider
{
    public function code(): string { return Payment::PROVIDER_MIXX; }
    public function displayName(): string { return 'Mixx by Yas'; }
    public function category(): string { return 'mobile_money'; }
}
