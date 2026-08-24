<?php

namespace App\Http\Controllers\Api\V1\Certificate;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\Certificate\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 10 — Admin certificate management.
 */
class AdminCertificateController extends Controller
{
    public function __construct(protected CertificateService $certs) {}

    /** GET /api/v1/admin/certificates */
    public function index(Request $request): JsonResponse
    {
        $q = Certificate::with(['user.profile', 'course:id,uuid,title']);
        if ($status = $request->query('status')) $q->where('status', $status);
        if ($search = $request->query('search')) {
            $q->where(function ($qq) use ($search) {
                $qq->where('cert_number', 'like', "%{$search}%")
                   ->orWhere('student_name_snapshot', 'like', "%{$search}%")
                   ->orWhere('course_title_snapshot', 'like', "%{$search}%");
            });
        }
        $page = $q->latest('issued_at')->paginate((int) $request->query('per_page', 25));

        $data = $page->getCollection()->map(fn (Certificate $c) => [
            'id' => $c->uuid,
            'cert_number' => $c->cert_number,
            'student_name' => $c->student_name_snapshot,
            'student_email' => $c->user?->email,
            'course_title' => $c->course_title_snapshot,
            'completion_date' => $c->completion_date?->toDateString(),
            'issued_at' => $c->issued_at?->toIso8601String(),
            'score_percentage' => $c->score_percentage !== null ? (float) $c->score_percentage : null,
            'status' => $c->status,
            'revoked_at' => $c->revoked_at?->toIso8601String(),
            'revoked_reason' => $c->revoked_reason,
        ]);

        return $this->success([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** POST /api/v1/admin/certificates/{uuid}/revoke */
    public function revoke(Certificate $certificate, Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        if (!$certificate->isActive()) {
            return $this->error('Certificate is not active — cannot revoke.', 422);
        }
        $updated = $this->certs->revoke($certificate, $request->user(), $data['reason']);
        return $this->success([
            'cert_number' => $updated->cert_number,
            'status' => $updated->status,
            'revoked_at' => $updated->revoked_at?->toIso8601String(),
            'revoked_reason' => $updated->revoked_reason,
        ], 'Certificate revoked');
    }
}
