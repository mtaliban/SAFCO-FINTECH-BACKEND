<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\PaymentProviderRegistry;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * SRS Module 12 — Payment initiation + status.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $service,
        private readonly PaymentProviderRegistry $registry,
    ) {}

    /** GET /payments/providers — list what we support (frontend picker). */
    public function providers(): JsonResponse
    {
        return $this->success(['providers' => $this->registry->catalog()]);
    }

    /** POST /invoices/{uuid}/pay */
    public function initiate(Invoice $invoice, Request $request): JsonResponse
    {
        $user = $request->user();
        if ($invoice->billed_user_id !== $user->id
            && !($user->hasRole('corporate_client') && $invoice->billed_org_id === $user->organization_id)
            && !$user->hasRole('system_admin')) {
            return $this->error('Not your invoice.', 403);
        }

        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in(Payment::ALL_PROVIDERS)],
            'msisdn' => ['nullable', 'regex:/^(\+?255|0)[67][0-9]{8}$/'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        // Mobile-money providers need a phone number.
        $mobileMoney = [Payment::PROVIDER_MPESA, Payment::PROVIDER_MIXX, Payment::PROVIDER_AIRTEL_MONEY];
        if (in_array($data['provider'], $mobileMoney, true) && empty($data['msisdn'])) {
            return $this->error('Phone number (msisdn) is required for mobile money.', 422);
        }

        $key = $data['idempotency_key'] ?? (string) Str::uuid();

        try {
            [$payment, $result] = array_values($this->service->initiate(
                invoice: $invoice,
                payer: $user,
                providerCode: $data['provider'],
                idempotencyKey: $key,
                msisdn: $this->normalizeMsisdn($data['msisdn'] ?? null),
            ));
        } catch (\DomainException | \InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('Payment initiation failed: ' . $e->getMessage(), 502);
        }

        return $this->success([
            'payment' => [
                'id' => $payment->uuid,
                'status' => $payment->status,
                'provider' => $payment->provider,
                'provider_ref' => $payment->provider_ref,
                'amount_tzs' => $payment->amount_tzs,
                'checkout_url' => $result->checkoutUrl,
                'user_instruction' => $result->userInstruction,
                'failure_message' => $result->failureMessage,
                'expires_at' => $payment->expires_at?->toIso8601String(),
            ],
        ], $result->accepted ? 201 : 422);
    }

    /** GET /payments/{payment:uuid} — status poll from frontend. */
    public function show(Payment $payment, Request $request): JsonResponse
    {
        if ($payment->user_id !== $request->user()->id && !$request->user()->hasRole('system_admin')) {
            return $this->error('Not your payment.', 403);
        }
        return $this->success([
            'payment' => [
                'id' => $payment->uuid,
                'status' => $payment->status,
                'provider' => $payment->provider,
                'provider_ref' => $payment->provider_ref,
                'amount_tzs' => $payment->amount_tzs,
                'failure_code' => $payment->failure_code,
                'failure_message' => $payment->failure_message,
                'completed_at' => $payment->completed_at?->toIso8601String(),
            ],
        ]);
    }

    /** Convert 07XXXXXXXX / +2557XXXXXXXX / 2557XXXXXXXX → 2557XXXXXXXX (E.164 without +). */
    private function normalizeMsisdn(?string $msisdn): ?string
    {
        if (!$msisdn) return null;
        $digits = preg_replace('/\D+/', '', $msisdn);
        if (str_starts_with($digits, '0')) $digits = '255' . substr($digits, 1);
        return $digits;
    }
}
