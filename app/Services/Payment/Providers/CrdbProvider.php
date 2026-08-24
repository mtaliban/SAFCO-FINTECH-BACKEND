<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;

/**
 * CRDB Bank — pull payment via bank aggregator (Selcom / Simba) or SimBanking API.
 */
class CrdbProvider extends AbstractProvider
{
    public function code(): string { return Payment::PROVIDER_CRDB; }
    public function displayName(): string { return 'CRDB Bank'; }
    public function category(): string { return 'bank'; }
}
