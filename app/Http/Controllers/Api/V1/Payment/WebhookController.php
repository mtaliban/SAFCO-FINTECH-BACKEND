<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentProviderRegistry;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SRS Module 12 — Public webhook receivers.
 *
 * Providers POST payment outcomes here. This endpoint:
 *   1. Extracts raw body BEFORE any middleware could mutate it (needed for HMAC).
 *   2. Delegates parsing + signature verification to the provider.
 *   3. Delegates state transition to the PaymentService (idempotent).
 *   4. ALWAYS returns 200 to prevent providers from spamming retries when we
 *      simply don't recognize the txn (we still log it for reconciliation).
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $service,
        private readonly PaymentProviderRegistry $registry,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        if (!$this->registry->has($provider)) {
            Log::warning('Webhook for unknown provider', ['provider' => $provider]);
            return response()->json(['received' => true], 200);
        }

        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true) ?: $request->all();
        $headers = $request->headers->all();

        try {
            $result = $this->registry->get($provider)->handleWebhook($payload, $headers, $rawBody);
        } catch (\Throwable $e) {
            Log::error('Webhook parse failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['received' => true, 'error' => 'parse_failed'], 200);
        }

        if (!$result->signatureVerified) {
            Log::warning('Webhook signature verification failed', [
                'provider' => $provider,
                'source_ip' => $request->ip(),
            ]);
            // Still log the event via applyWebhookResult so we can inspect it, but do NOT settle.
            // We fake a failed status result to keep the audit trail without triggering fulfillment.
            $this->service->applyWebhookResult($provider,
                new \App\Services\Payment\DTOs\WebhookResult(
                    providerRef: $result->providerRef,
                    status: \App\Models\Payment::STATUS_PENDING,
                    failureCode: 'signature_invalid',
                    signatureVerified: false,
                    meta: $result->meta,
                ),
                $payload,
                $request->ip(),
            );
            return response()->json(['received' => true, 'signature' => 'invalid'], 200);
        }

        $payment = $this->service->applyWebhookResult($provider, $result, $payload, $request->ip());

        return response()->json([
            'received' => true,
            'matched' => (bool) $payment,
            'status' => $payment?->status,
        ], 200);
    }
}
