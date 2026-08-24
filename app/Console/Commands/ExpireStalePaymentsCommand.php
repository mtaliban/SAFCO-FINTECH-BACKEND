<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;

/**
 * SRS Module 12 — transition pending payments to 'expired' after their window
 * closes. Prevents infinite-pending rows if a user abandons checkout.
 *
 * Scheduled every 5 minutes via app/Console/Kernel.php.
 */
class ExpireStalePaymentsCommand extends Command
{
    protected $signature = 'payments:expire-stale';
    protected $description = 'Mark pending payments that passed expires_at as expired';

    public function handle(): int
    {
        $count = Payment::where('status', Payment::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => Payment::STATUS_EXPIRED,
                'completed_at' => now(),
                'failure_code' => 'expired',
                'failure_message' => 'Payment window elapsed without provider confirmation.',
            ]);

        $this->info("Expired $count stale payment(s).");
        return self::SUCCESS;
    }
}
