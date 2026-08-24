<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;

/**
 * NBC Bank — pull payment via bank aggregator.
 */
class NbcProvider extends AbstractProvider
{
    public function code(): string { return Payment::PROVIDER_NBC; }
    public function displayName(): string { return 'NBC Bank'; }
    public function category(): string { return 'bank'; }
}
