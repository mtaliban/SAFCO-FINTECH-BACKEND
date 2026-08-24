<?php

namespace App\Services\Payment;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Payment\DTOs\InitiationRequest;
use App\Services\Payment\DTOs\InitiationResult;
use App\Services\Payment\DTOs\WebhookResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SRS Module 12 — Payment lifecycle orchestration.
 *
 *   initiate() — create Payment row + call provider (idempotent via idempotency_key)
 *   applyWebhookResult() — update Payment + mark invoice paid + trigger side-effects
 *                          Safe to call multiple times (webhooks retry).
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentProviderRegistry $providers,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * Initiate a payment. Returns the local Payment + the provider result.
     * If the same idempotency_key was used before, returns the existing Payment.
     */
    public function initiate(
        Invoice $invoice,
        User $payer,
        string $providerCode,
        string $idempotencyKey,
        ?string $msisdn = null,
        ?string $callbackUrl = null,
        ?string $returnUrl = null,
    ): array {
        if ($invoice->isPaid()) {
            throw new \DomainException('Invoice is already paid.');
        }
        if (!$this->providers->has($providerCode)) {
            throw new \InvalidArgumentException("Unknown provider $providerCode");
        }

        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            // Idempotent replay — return whatever we recorded earlier.
            return [
                'payment' => $existing,
                'result' => new InitiationResult(
                    accepted: !in_array($existing->status, [Payment::STATUS_FAILED, Payment::STATUS_CANCELLED, Payment::STATUS_EXPIRED], true),
                    providerRef: $existing->provider_ref ?? '',
                    checkoutUrl: $existing->meta['checkout_url'] ?? null,
                    userInstruction: $existing->meta['user_instruction'] ?? null,
                    failureCode: $existing->failure_code,
                    failureMessage: $existing->failure_message,
                    meta: $existing->meta ?? [],
                ),
            ];
        }

        $payment = DB::transaction(function () use ($invoice, $payer, $providerCode, $idempotencyKey, $msisdn) {
            return Payment::create([
                'uuid' => (string) Str::uuid(),
                'invoice_id' => $invoice->id,
                'user_id' => $payer->id,
                'provider' => $providerCode,
                'idempotency_key' => $idempotencyKey,
                'msisdn' => $msisdn,
                'amount_tzs' => $invoice->total_tzs,
                'currency' => $invoice->currency,
                'status' => Payment::STATUS_PENDING,
                'initiated_at' => now(),
                'expires_at' => now()->addMinutes(15),
            ]);
        });

        $provider = $this->providers->get($providerCode);
        $req = new InitiationRequest(
            amountTzs: $invoice->total_tzs,
            description: $invoice->description ?? "Invoice {$invoice->invoice_number}",
            callbackUrl: $callbackUrl ?? url("/api/v1/payments/webhook/$providerCode"),
            returnUrl: $returnUrl ?? url("/billing"),
            msisdn: $msisdn,
            payerName: $payer->profile?->full_name ?? null,
            payerEmail: $payer->email,
        );

        try {
            $result = $provider->initiate($payment, $req);
        } catch (\Throwable $e) {
            Log::error('Payment provider initiate threw', [
                'provider' => $providerCode,
                'payment' => $payment->uuid,
                'error' => $e->getMessage(),
            ]);
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_code' => 'provider_exception',
                'failure_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            throw $e;
        }

        $payment->update([
            'provider_ref' => $result->providerRef ?: null,
            'status' => $result->accepted ? Payment::STATUS_PENDING : Payment::STATUS_FAILED,
            'failure_code' => $result->failureCode,
            'failure_message' => $result->failureMessage,
            'meta' => array_merge($payment->meta ?? [], [
                'user_instruction' => $result->userInstruction,
                'checkout_url' => $result->checkoutUrl,
                ...$result->meta,
            ]),
            'completed_at' => $result->accepted ? null : now(),
        ]);

        return ['payment' => $payment->fresh(), 'result' => $result];
    }

    /**
     * Process an inbound webhook payload. Idempotent — safe on retry.
     * Uses row-level lock so double-fires can't cause double-enrollment.
     */
    public function applyWebhookResult(string $providerCode, WebhookResult $result, array $rawPayload, string $sourceIp): ?Payment
    {
        if (!$this->providers->has($providerCode)) {
            Log::warning('Webhook for unknown provider', ['provider' => $providerCode]);
            return null;
        }

        $payment = Payment::where('provider', $providerCode)
            ->where('provider_ref', $result->providerRef)
            ->lockForUpdate()
            ->first();

        // Log EVERY webhook, even if we can't match it (for reconciliation).
        PaymentEvent::create([
            'payment_id' => $payment?->id,
            'provider' => $providerCode,
            'event_type' => PaymentEvent::TYPE_WEBHOOK,
            'payload' => $rawPayload,
            'signature_verified' => $result->signatureVerified,
            'source_ip' => $sourceIp,
        ]);

        if (!$payment) return null;

        // Idempotency: if already finalized, do nothing.
        if ($payment->isFinalized()) return $payment;

        // SECURITY: verify the provider's reported amount matches what we expected.
        // Providers occasionally callback with wrong amount (bug or attack); reject.
        if ($result->isSuccess() && $result->amountTzs !== null
            && $result->amountTzs !== (int) $payment->amount_tzs) {
            Log::warning('Webhook amount mismatch — payment rejected', [
                'payment_uuid' => $payment->uuid,
                'expected_tzs' => $payment->amount_tzs,
                'received_tzs' => $result->amountTzs,
                'provider' => $providerCode,
            ]);
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_code' => 'amount_mismatch',
                'failure_message' => "Provider reported {$result->amountTzs} but invoice is {$payment->amount_tzs}",
                'completed_at' => now(),
            ]);
            return $payment->fresh();
        }

        // Currency mismatch is equally fatal.
        if ($result->isSuccess() && $result->currency !== null
            && strtoupper($result->currency) !== strtoupper((string) $payment->currency)) {
            Log::warning('Webhook currency mismatch — payment rejected', [
                'payment_uuid' => $payment->uuid,
                'expected' => $payment->currency,
                'received' => $result->currency,
            ]);
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_code' => 'currency_mismatch',
                'failure_message' => "Provider reported {$result->currency} but invoice is {$payment->currency}",
                'completed_at' => now(),
            ]);
            return $payment->fresh();
        }

        return DB::transaction(function () use ($payment, $result) {
            // Refresh the invoice inside the transaction with a row lock.
            $invoice = $payment->invoice()->lockForUpdate()->first();

            // CORRECTNESS: if the invoice was voided or refunded before this
            // late callback arrived, we must NOT revive it. Record the payment
            // as reversed and leave the invoice alone.
            if ($result->isSuccess() && in_array($invoice->status, [
                \App\Models\Invoice::STATUS_VOID,
                \App\Models\Invoice::STATUS_REFUNDED,
            ], true)) {
                Log::warning('Late webhook for finalized invoice — marking payment reversed', [
                    'invoice' => $invoice->invoice_number,
                    'invoice_status' => $invoice->status,
                    'payment_uuid' => $payment->uuid,
                ]);
                $payment->update([
                    'status' => Payment::STATUS_REVERSED,
                    'failure_code' => 'invoice_finalized',
                    'failure_message' => "Invoice was {$invoice->status} before webhook arrived",
                    'completed_at' => now(),
                ]);
                return $payment->fresh();
            }

            $payment->update([
                'status' => $result->status,
                'failure_code' => $result->failureCode,
                'failure_message' => $result->failureMessage,
                'completed_at' => now(),
                'meta' => array_merge($payment->meta ?? [], [
                    'webhook_meta' => $result->meta,
                ]),
            ]);

            if ($result->isSuccess()) {
                $this->invoices->markPaid($invoice);
                $this->fulfillInvoice($invoice, $payment->user_id);
            }

            return $payment->fresh();
        });
    }

    /**
     * Fulfill the invoice's subject (currently: auto-enroll the payer in the paid course).
     * Extend here as new invoice subjects appear (subscriptions, etc.).
     */
    private function fulfillInvoice(Invoice $invoice, int $userId): void
    {
        $user = \App\Models\User::find($userId);
        if (!$user) return;

        $dispatcher = app(\App\Services\Notifications\NotificationDispatcher::class);

        if ($invoice->subject_type === \App\Models\Course::class) {
            $enrollment = Enrollment::firstOrCreate(
                ['user_id' => $userId, 'course_id' => $invoice->subject_id],
                ['uuid' => (string) Str::uuid(), 'enrolled_at' => now(), 'progress_percentage' => 0]
            );

            $course = \App\Models\Course::find($invoice->subject_id);
            if ($enrollment->wasRecentlyCreated && $course) {
                $dispatcher->dispatch($user, 'course.enrolled', [
                    'course_title' => $course->title,
                    'action_url' => config('app.url') . '/student/courses/' . $course->uuid,
                    'action_label' => 'Anza kozi',
                ]);
            }
        }

        // Payment receipt (fires regardless of subject_type)
        $dispatcher->dispatch($user, 'payment.received', [
            'amount' => (int) $invoice->total_tzs,
            'description' => $invoice->description ?? $invoice->invoice_number,
            'invoice_number' => $invoice->invoice_number,
            'action_url' => config('app.url') . '/billing',
            'action_label' => 'Ona invoice',
        ]);
    }
}
