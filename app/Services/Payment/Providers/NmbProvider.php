<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;

/**
 * NMB Bank — pull payment via NMB API / Selcom.
 */
class NmbProvider extends AbstractProvider
{
    public function code(): string { return Payment::PROVIDER_NMB; }
    public function displayName(): string { return 'NMB Bank'; }
    public function category(): string { return 'bank'; }
}
