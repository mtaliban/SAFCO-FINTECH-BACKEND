<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SRS 3.1 Admin — Manage Subscriptions / Billing.
 *
 * GET  /admin/billing         — paginated invoices with filters + revenue summary
 * GET  /admin/billing/export  — CSV download (max 5000 rows)
 * POST /admin/billing/{uuid}/void — void an invoice (delegates to InvoiceService)
 */
class AdminBillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['billedUser:id,email', 'billedOrg:id,name'])
            ->orderByDesc('issued_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->get('date_from')) {
            $query->whereDate('issued_at', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('issued_at', '<=', $to);
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('billedUser', fn ($u) => $u->where('email', 'like', "%{$search}%"));
            });
        }

        $paginated = $query->paginate(25);

        // Revenue summary (scoped to same filters)
        $revBase = Invoice::where('status', 'paid');
        if ($from) $revBase->whereDate('issued_at', '>=', $from);
        if ($to)   $revBase->whereDate('issued_at', '<=', $to);

        $revenue = [
            'total_tzs' => (int) $revBase->sum('total_tzs'),
            'paid_count' => (int) $revBase->count(),
        ];

        $counts = [
            'draft'    => Invoice::where('status', 'draft')->count(),
            'issued'   => Invoice::where('status', 'issued')->count(),
            'paid'     => Invoice::where('status', 'paid')->count(),
            'void'     => Invoice::where('status', 'void')->count(),
            'refunded' => Invoice::whereIn('status', ['refunded', 'partially_refunded'])->count(),
        ];

        return $this->success([
            'invoices' => collect($paginated->items())->map(fn (Invoice $inv) => $this->serialize($inv)),
            'meta'     => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
            ],
            'revenue' => $revenue,
            'counts'  => $counts,
        ]);
    }

    public function export(Request $request): Response
    {
        $query = Invoice::with(['billedUser:id,email', 'billedOrg:id,name'])
            ->orderByDesc('issued_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->get('date_from')) {
            $query->whereDate('issued_at', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('issued_at', '<=', $to);
        }

        $rows = $query->limit(5000)->get();

        $header = "Invoice #,Status,User Email,Organization,Subtotal (TZS),Tax (TZS),Total (TZS),Issued At,Due At,Paid At\n";

        $lines = $rows->map(function (Invoice $inv) {
            return implode(',', [
                $inv->invoice_number,
                $inv->status,
                '"' . ($inv->billedUser?->email ?? '') . '"',
                '"' . ($inv->billedOrg?->name ?? '') . '"',
                $inv->subtotal_tzs,
                $inv->tax_tzs,
                $inv->total_tzs,
                $inv->issued_at?->toDateString() ?? '',
                $inv->due_at?->toDateString() ?? '',
                $inv->paid_at?->toDateString() ?? '',
            ]);
        })->implode("\n");

        return response($header . $lines, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="invoices-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    private function serialize(Invoice $inv): array
    {
        return [
            'id'             => $inv->uuid,
            'invoice_number' => $inv->invoice_number,
            'status'         => $inv->status,
            'description'    => $inv->description,
            'subtotal_tzs'   => $inv->subtotal_tzs,
            'tax_tzs'        => $inv->tax_tzs,
            'total_tzs'      => $inv->total_tzs,
            'currency'       => $inv->currency,
            'issued_at'      => $inv->issued_at?->toIso8601String(),
            'due_at'         => $inv->due_at?->toIso8601String(),
            'paid_at'        => $inv->paid_at?->toIso8601String(),
            'billed_to'      => [
                'email' => $inv->billedUser?->email,
                'org'   => $inv->billedOrg?->name,
            ],
        ];
    }
}
