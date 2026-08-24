<?php

namespace App\Http\Controllers\Api\V1\Certificate;

use App\Http\Controllers\Controller;
use App\Services\Certificate\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 10 — PUBLIC certificate verification (no auth).
 *
 *   GET  /api/v1/verify/certificate/{certNumber}   — machine-readable verification
 *   POST /api/v1/verify/search                     — search by cert number OR QR-scanned URL
 *
 * All routes here are rate-limited to prevent enumeration attacks against valid cert numbers.
 */
class CertificateVerificationController extends Controller
{
    public function __construct(protected CertificateService $certs) {}

    /** GET /api/v1/verify/certificate/{certNumber} */
    public function verify(string $certNumber): JsonResponse
    {
        $result = $this->certs->verifyByCertNumber($certNumber);

        // Never leak internal ids or user emails on the public endpoint
        if ($result['status'] === 'not_found') {
            return response()->json([
                'success' => true,
                'data' => ['status' => 'not_found', 'cert_number' => $certNumber, 'certificate' => null],
            ]);
        }

        $c = $result['certificate'];
        return response()->json([
            'success' => true,
            'data' => [
                'status' => $result['status'],
                'cert_number' => $c->cert_number,
                'certificate' => [
                    'student_name' => $c->student_name_snapshot,
                    'course_title' => $c->course_title_snapshot,
                    'course_category' => $c->course?->category,
                    'completion_date' => $c->completion_date?->toDateString(),
                    'issued_at' => $c->issued_at?->toIso8601String(),
                    'score_percentage' => $c->score_percentage !== null ? (float) $c->score_percentage : null,
                    'revoked_at' => $c->revoked_at?->toIso8601String(),
                    'revoked_reason' => $c->revoked_reason,
                ],
                'issuer' => [
                    'name' => 'SAFCO FINTECH LMS',
                    'verified_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    /** POST /api/v1/verify/search — accepts cert_number or a full verify URL (from QR scan). */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:400'],
        ]);
        $q = trim($data['query']);

        // If it looks like our verify URL, extract the cert number
        if (preg_match('#/verify/certificate/([A-Z0-9\-]+)#i', $q, $m)) {
            $certNumber = strtoupper($m[1]);
        } else {
            $certNumber = strtoupper($q);
        }

        return $this->verify($certNumber);
    }
}
