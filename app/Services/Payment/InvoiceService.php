<?php

namespace App\Services\Payment;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SRS Module 12 — Invoice orchestration.
 *
 * Responsibilities:
 *   - Generate deterministic invoice numbers (SAFCO-INV-2026-000001)
 *   - Compute VAT (18% by default) and total
 *   - Create a race-safe row (lockForUpdate to prevent duplicate active invoices)
 *   - Render PDF via DomPDF using the same pattern as certificates
 */
class InvoiceService
{
    /**
     * Get or create the current OPEN invoice for a user against a course.
     * Race-safe: two parallel calls will both return the same invoice.
     */
    public function issueForCourse(User $billedUser, Course $course, ?int $orgId = null): Invoice
    {
        return DB::transaction(function () use ($billedUser, $course, $orgId) {
            $existing = Invoice::where('billed_user_id', $billedUser->id)
                ->where('subject_type', Course::class)
                ->where('subject_id', $course->id)
                ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PAID])
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if ($existing) return $existing;

            $subtotal = (int) ($course->price_tzs ?? 0);
            if ($subtotal <= 0) {
                throw new \DomainException('Course is free — no invoice needed.');
            }

            $vatPct = (int) config('payments.vat_percent', 18);
            $tax    = (int) round($subtotal * $vatPct / 100);
            $total  = $subtotal + $tax;

            return Invoice::create([
                'uuid' => (string) Str::uuid(),
                'invoice_number' => $this->nextInvoiceNumber(),
                'billed_user_id' => $billedUser->id,
                'billed_org_id' => $orgId,
                'subject_type' => Course::class,
                'subject_id' => $course->id,
                'subtotal_tzs' => $subtotal,
                'tax_tzs' => $tax,
                'total_tzs' => $total,
                'currency' => 'TZS',
                'status' => Invoice::STATUS_ISSUED,
                'description' => "Enrollment: {$course->title}",
                'line_items' => [[
                    'name' => $course->title,
                    'qty' => 1,
                    'unit_tzs' => $subtotal,
                    'total_tzs' => $subtotal,
                ]],
                'issued_at' => now(),
                'due_at' => now()->addDays(7),
            ]);
        });
    }

    /**
     * Mark an invoice as paid. Used from PaymentService after a webhook succeeds.
     * Idempotent: safe to call twice.
     */
    public function markPaid(Invoice $invoice): void
    {
        if ($invoice->isPaid()) return;

        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function void(Invoice $invoice, ?string $reason = null): void
    {
        if ($invoice->isPaid()) {
            throw new \DomainException('Cannot void a paid invoice — refund instead.');
        }
        $invoice->update([
            'status' => Invoice::STATUS_VOID,
            'voided_at' => now(),
            'meta' => array_merge($invoice->meta ?? [], ['void_reason' => $reason]),
        ]);
    }

    /**
     * Sequential invoice number using a DB-level counter (safe under load).
     * Format: SAFCO-INV-2026-000001
     */
    private function nextInvoiceNumber(): string
    {
        $prefix = config('payments.invoice_prefix', 'SAFCO-INV');
        $year = now()->format('Y');
        // Find the highest existing number this year
        $last = Invoice::where('invoice_number', 'like', "$prefix-$year-%")
            ->orderByDesc('invoice_number')
            ->lockForUpdate()
            ->value('invoice_number');

        $n = 1;
        if ($last) {
            $parts = explode('-', $last);
            $n = (int) end($parts) + 1;
        }
        return sprintf('%s-%s-%06d', $prefix, $year, $n);
    }

    /**
     * Render the invoice as a PDF.
     * Returns raw bytes so the caller can stream / attach.
     */
    public function renderPdf(Invoice $invoice): string
    {
        $invoice->loadMissing(['billedUser', 'billedOrg', 'subject']);
        return Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'issuer' => config('payments.issuer'),
            'vatPct' => config('payments.vat_percent', 18),
        ])->setPaper('a4', 'portrait')->output();
    }

    public function renderReceiptPdf(Invoice $invoice): string
    {
        $invoice->loadMissing(['billedUser', 'billedOrg', 'subject', 'payments']);
        $payment = $invoice->successfulPayment();
        return Pdf::loadView('invoices.receipt', [
            'invoice' => $invoice,
            'payment' => $payment,
            'issuer' => config('payments.issuer'),
            'vatPct' => config('payments.vat_percent', 18),
        ])->setPaper('a4', 'portrait')->output();
    }
}
