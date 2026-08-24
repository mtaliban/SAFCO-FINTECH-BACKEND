<?php

namespace App\Services\Payment;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Facades\CauserResolver;

/**
 * SRS Module 12 — Refund lifecycle.
 *
 * A refund is created against a SUCCEEDED payment.
 * Business rules:
 *   - Only paid invoices can be refunded.
 *   - Amount cannot exceed (original payment amount - already-refunded amount).
 *   - Partial refunds move invoice to 'partially_refunded'; full refunds to 'refunded'.
 *   - Refunding a course invoice REVOKES the auto-enrollment (side-effect).
 *   - Every refund is audit-logged via spatie/laravel-activitylog + PaymentEvent.
 */
class RefundService
{
    public function issue(Payment $payment, User $admin, int $amountTzs, string $reason): Refund
    {
        if ($payment->status !== Payment::STATUS_SUCCEEDED) {
            throw new \DomainException('Only succeeded payments can be refunded.');
        }
        if ($amountTzs <= 0) {
            throw new \DomainException('Refund amount must be positive.');
        }

        $invoice = $payment->invoice()->lockForUpdate()->first();
        if (!$invoice->isPaid() && $invoice->status !== Invoice::STATUS_PARTIALLY_REFUNDED) {
            throw new \DomainException("Invoice is not in a refundable state: {$invoice->status}");
        }

        $alreadyRefunded = (int) $invoice->refundedAmount();
        $maxRefundable = (int) $payment->amount_tzs - $alreadyRefunded;
        if ($amountTzs > $maxRefundable) {
            throw new \DomainException(
                "Refund amount ($amountTzs) exceeds remaining refundable balance ($maxRefundable)"
            );
        }

        return DB::transaction(function () use ($payment, $invoice, $admin, $amountTzs, $reason, $alreadyRefunded) {
            $refund = Refund::create([
                'uuid' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount_tzs' => $amountTzs,
                'reason' => $reason,
                'requested_by' => $admin->id,
                // In mock mode we auto-succeed; real providers would keep this pending
                // until their API confirms.
                'status' => Refund::STATUS_SUCCEEDED,
                'requested_at' => now(),
                'completed_at' => now(),
            ]);

            // Update invoice status
            $totalRefunded = $alreadyRefunded + $amountTzs;
            if ($totalRefunded >= (int) $payment->amount_tzs) {
                $invoice->update(['status' => Invoice::STATUS_REFUNDED]);
                $this->revokeFulfillment($invoice, $payment->user_id);
            } else {
                $invoice->update(['status' => Invoice::STATUS_PARTIALLY_REFUNDED]);
            }

            // Audit log via spatie/laravel-activitylog (used elsewhere in the app)
            activity('payments')
                ->causedBy($admin)
                ->performedOn($refund)
                ->withProperties([
                    'invoice_number' => $invoice->invoice_number,
                    'payment_uuid' => $payment->uuid,
                    'amount_tzs' => $amountTzs,
                    'reason' => $reason,
                    'total_refunded_tzs' => $totalRefunded,
                    'invoice_status_after' => $invoice->fresh()->status,
                ])
                ->log('refund_issued');

            // Also record in append-only payment_events for reconciliation.
            PaymentEvent::create([
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'event_type' => PaymentEvent::TYPE_REFUND_REQUEST,
                'payload' => [
                    'refund_uuid' => $refund->uuid,
                    'amount_tzs' => $amountTzs,
                    'reason' => $reason,
                    'requested_by' => $admin->id,
                ],
                'signature_verified' => true, // internal action, not a webhook
            ]);

            return $refund;
        });
    }

    /**
     * Reverse the fulfillment side-effects of a fully refunded invoice.
     * Currently: revoke the auto-enrollment for course invoices.
     */
    private function revokeFulfillment(Invoice $invoice, int $userId): void
    {
        if ($invoice->subject_type === \App\Models\Course::class) {
            // Only remove enrollment if it hasn't been used (progress < 100).
            // Otherwise the student has consumed value — refund is monetary only.
            $enrollment = Enrollment::where('user_id', $userId)
                ->where('course_id', $invoice->subject_id)
                ->first();
            if ($enrollment && (float) $enrollment->progress_percentage < 100) {
                $enrollment->delete();
                Log::info('Enrollment revoked due to refund', [
                    'invoice' => $invoice->invoice_number,
                    'user_id' => $userId,
                ]);
            }
        }
    }
}
