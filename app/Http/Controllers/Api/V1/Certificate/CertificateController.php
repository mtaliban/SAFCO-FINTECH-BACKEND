<?php

namespace App\Http\Controllers\Api\V1\Certificate;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\Certificate\CertificateService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SRS Module 10 — Certificate endpoints for logged-in students/trainers.
 *
 *   GET /api/v1/student/certificates          — my certificates
 *   GET /api/v1/certificates/{uuid}           — one of MY certificates (or trainer/admin)
 *   GET /api/v1/certificates/{uuid}/pdf       — download A4 landscape PDF
 *   GET /api/v1/certificates/{uuid}/qr        — SVG QR image (data-only)
 */
class CertificateController extends Controller
{
    public function __construct(protected CertificateService $certs) {}

    public function index(Request $request): JsonResponse
    {
        $rows = Certificate::with(['course:id,uuid,title,category'])
            ->where('user_id', $request->user()->id)
            ->latest('issued_at')
            ->get()
            ->map(fn (Certificate $c) => $this->transform($c));

        return $this->success($rows);
    }

    public function show(Certificate $certificate, Request $request): JsonResponse
    {
        $this->authorizeOwnership($certificate, $request);
        return $this->success($this->transform($certificate->load(['course:id,uuid,title,category'])));
    }

    public function pdf(Certificate $certificate, Request $request): Response
    {
        $this->authorizeOwnership($certificate, $request);

        $qrSvg = $this->certs->renderQrSvg($certificate, 260);
        $verifyUrl = $this->certs->verificationUrl($certificate->cert_number);

        $pdf = Pdf::loadView('certificate.pdf', [
            'cert' => $certificate,
            'qrSvg' => $qrSvg,
            'verifyUrl' => $verifyUrl,
        ])->setPaper('a4', 'landscape');

        return $pdf->download("{$certificate->cert_number}.pdf");
    }

    public function qr(Certificate $certificate, Request $request): Response
    {
        $this->authorizeOwnership($certificate, $request);
        $svg = $this->certs->renderQrSvg($certificate, 320);
        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    /* ---------------- Helpers ---------------- */

    protected function authorizeOwnership(Certificate $cert, Request $request): void
    {
        $user = $request->user();
        if ($cert->user_id === $user->id) return;
        if ($user->hasAnyRole(['system_admin', 'trainer'])) return;
        abort(403, 'Not your certificate.');
    }

    protected function transform(Certificate $c): array
    {
        return [
            'id' => $c->uuid,
            'cert_number' => $c->cert_number,
            'student_name' => $c->student_name_snapshot,
            'course' => [
                'id' => $c->course?->uuid,
                'title' => $c->course_title_snapshot,   // frozen — safe against course renames
                'category' => $c->course?->category,
            ],
            'completion_date' => $c->completion_date?->toDateString(),
            'issued_at' => $c->issued_at?->toIso8601String(),
            'score_percentage' => $c->score_percentage !== null ? (float) $c->score_percentage : null,
            'status' => $c->status,
            'revoked_at' => $c->revoked_at?->toIso8601String(),
            'revoked_reason' => $c->revoked_reason,
            'verify_url' => $this->certs->verificationUrl($c->cert_number),
        ];
    }
}
