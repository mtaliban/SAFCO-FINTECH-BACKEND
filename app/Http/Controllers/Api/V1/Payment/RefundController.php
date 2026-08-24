<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 12 — Admin refund endpoint.
 * Only system_admin can issue refunds; scoped to a specific succeeded Payment.
 */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    /** POST /payments/{payment:uuid}/refund */
    public function store(Payment $payment, Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_tzs' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $refund = $this->refunds->issue(
                payment: $payment,
                admin: $request->user(),
                amountTzs: (int) $data['amount_tzs'],
                reason: $data['reason'],
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'refund' => [
                'id' => $refund->uuid,
                'amount_tzs' => $refund->amount_tzs,
                'status' => $refund->status,
                'reason' => $refund->reason,
                'requested_at' => $refund->requested_at?->toIso8601String(),
                'completed_at' => $refund->completed_at?->toIso8601String(),
            ],
            'invoice_status' => $payment->fresh()->invoice->status,
        ], 'Refund issued', 201);
    }
}
