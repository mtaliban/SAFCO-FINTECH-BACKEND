<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Invoice;
use App\Services\Payment\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * SRS Module 12 — Invoice endpoints.
 *
 * - GET  /invoices                 — list mine (student/corporate/admin scope-filtered)
 * - GET  /invoices/{uuid}          — show
 * - POST /invoices                 — create for a course (student self-service)
 * - GET  /invoices/{uuid}/pdf      — download PDF (rate-limited)
 * - GET  /invoices/{uuid}/receipt  — download receipt PDF (only when paid)
 * - POST /invoices/{uuid}/void     — admin only
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = Invoice::with(['subject', 'billedOrg:id,name'])
            ->orderByDesc('issued_at')
            ->limit(50);

        if ($user->hasRole('system_admin')) {
            // no scope
        } elseif ($user->hasRole('corporate_client')) {
            $q->where(fn ($w) => $w
                ->where('billed_user_id', $user->id)
                ->orWhere('billed_org_id', $user->organization_id));
        } else {
            $q->where('billed_user_id', $user->id);
        }

        return $this->success([
            'invoices' => $q->get()->map(fn (Invoice $inv) => $this->serialize($inv)),
        ]);
    }

    public function show(Invoice $invoice, Request $request): JsonResponse
    {
        $this->authorizeInvoice($invoice, $request);
        $invoice->load(['payments', 'refunds', 'subject']);
        return $this->success($this->serialize($invoice, includePayments: true));
    }

    public function storeForCourse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_uuid' => ['required', 'uuid', 'exists:courses,uuid'],
        ]);

        $course = Course::where('uuid', $data['course_uuid'])->firstOrFail();
        if ($course->isFree()) {
            return $this->error('This course is free — no invoice needed.', 422);
        }
        if ($course->status !== 'published') {
            return $this->error('Course is not available for purchase.', 422);
        }

        $user = $request->user();
        try {
            $invoice = $this->invoices->issueForCourse(
                billedUser: $user,
                course: $course,
                orgId: $user->hasRole('corporate_client') ? $user->organization_id : null,
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->serialize($invoice), 201);
    }

    public function pdf(Invoice $invoice, Request $request): Response
    {
        $this->authorizeInvoice($invoice, $request);
        $bytes = $this->invoices->renderPdf($invoice);
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$invoice->invoice_number}.pdf\"",
        ]);
    }

    public function receipt(Invoice $invoice, Request $request): Response|JsonResponse
    {
        $this->authorizeInvoice($invoice, $request);
        if (!$invoice->isPaid()) {
            return $this->error('Receipt available only after payment.', 422);
        }
        $bytes = $this->invoices->renderReceiptPdf($invoice);
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"receipt-{$invoice->invoice_number}.pdf\"",
        ]);
    }

    public function void(Invoice $invoice, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        try {
            $this->invoices->void($invoice, $data['reason'] ?? null);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success($this->serialize($invoice->fresh()));
    }

    private function authorizeInvoice(Invoice $invoice, Request $request): void
    {
        $user = $request->user();
        if ($user->hasRole('system_admin')) return;
        if ($invoice->billed_user_id === $user->id) return;
        if ($user->hasRole('corporate_client') && $invoice->billed_org_id === $user->organization_id) return;
        abort(403, 'Not your invoice.');
    }

    private function serialize(Invoice $inv, bool $includePayments = false): array
    {
        $data = [
            'id' => $inv->uuid,
            'invoice_number' => $inv->invoice_number,
            'status' => $inv->status,
            'description' => $inv->description,
            'subtotal_tzs' => $inv->subtotal_tzs,
            'tax_tzs' => $inv->tax_tzs,
            'total_tzs' => $inv->total_tzs,
            'currency' => $inv->currency,
            'line_items' => $inv->line_items,
            'issued_at' => $inv->issued_at?->toIso8601String(),
            'due_at' => $inv->due_at?->toIso8601String(),
            'paid_at' => $inv->paid_at?->toIso8601String(),
            'billed_to' => [
                'email' => $inv->billedUser?->email,
                'org' => $inv->billedOrg?->name,
            ],
        ];
        if ($includePayments) {
            $data['payments'] = $inv->payments->map(fn ($p) => [
                'id' => $p->uuid,
                'provider' => $p->provider,
                'status' => $p->status,
                'amount_tzs' => $p->amount_tzs,
                'initiated_at' => $p->initiated_at?->toIso8601String(),
                'completed_at' => $p->completed_at?->toIso8601String(),
                'failure_message' => $p->failure_message,
                'meta' => $p->meta,
            ]);
        }
        return $data;
    }
}
