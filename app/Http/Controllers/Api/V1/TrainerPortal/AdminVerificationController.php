<?php

namespace App\Http\Controllers\Api\V1\TrainerPortal;

use App\Http\Controllers\Controller;
use App\Models\TrainerCertification;
use App\Models\TrainerQualification;
use App\Models\TrainerReview;
use App\Services\TrainerPortal\ReviewService;
use App\Services\TrainerPortal\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 13 — Admin: verify or reject trainer credentials + moderate reviews.
 * All actions are audit-logged via spatie/laravel-activitylog.
 */
class AdminVerificationController extends Controller
{
    public function __construct(
        private readonly VerificationService $verification,
        private readonly ReviewService $reviews,
    ) {}

    /** GET /admin/trainer-verifications — pending queue */
    public function pending(): JsonResponse
    {
        $quals = TrainerQualification::with([
            'profile.user:id,uuid,email',
            'profile.user.profile:user_id,full_name',
        ])->where('verification_status', 'pending')
          ->latest()->limit(100)->get();

        $certs = TrainerCertification::with([
            'profile.user:id,uuid,email',
            'profile.user.profile:user_id,full_name',
        ])->where('verification_status', 'pending')
          ->latest()->limit(100)->get();

        return $this->success([
            'qualifications' => $quals->map(fn ($q) => [
                'id' => $q->uuid,
                'trainer_name' => $q->profile->user->profile?->full_name ?? $q->profile->user->email,
                'trainer_slug' => $q->profile->public_slug,
                'institution' => $q->institution,
                'degree' => $q->degree,
                'field_of_study' => $q->field_of_study,
                'has_proof' => !empty($q->proof_file_path),
                'created_at' => $q->created_at?->toIso8601String(),
            ]),
            'certifications' => $certs->map(fn ($c) => [
                'id' => $c->uuid,
                'trainer_name' => $c->profile->user->profile?->full_name ?? $c->profile->user->email,
                'trainer_slug' => $c->profile->public_slug,
                'name' => $c->name,
                'issuer' => $c->issuer,
                'credential_id' => $c->credential_id,
                'verification_url' => $c->verification_url,
                'issue_date' => $c->issue_date?->toDateString(),
                'expiry_date' => $c->expiry_date?->toDateString(),
                'has_proof' => !empty($c->proof_file_path),
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'totals' => [
                'pending_qualifications' => $quals->count(),
                'pending_certifications' => $certs->count(),
            ],
        ]);
    }

    public function verifyQualification(TrainerQualification $qualification, Request $request): JsonResponse
    {
        $this->verification->verifyQualification($qualification, $request->user());
        return $this->success(['id' => $qualification->uuid, 'status' => 'verified']);
    }

    public function rejectQualification(TrainerQualification $qualification, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->verification->rejectQualification($qualification, $request->user(), $data['reason']);
        return $this->success(['id' => $qualification->uuid, 'status' => 'rejected', 'reason' => $data['reason']]);
    }

    public function verifyCertification(TrainerCertification $certification, Request $request): JsonResponse
    {
        $this->verification->verifyCertification($certification, $request->user());
        return $this->success(['id' => $certification->uuid, 'status' => 'verified']);
    }

    public function rejectCertification(TrainerCertification $certification, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->verification->rejectCertification($certification, $request->user(), $data['reason']);
        return $this->success(['id' => $certification->uuid, 'status' => 'rejected', 'reason' => $data['reason']]);
    }

    /** POST /admin/trainer-reviews/{review}/hide */
    public function hideReview(TrainerReview $review, Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->reviews->hide($review, $request->user(), $data['note'] ?? null);
        return $this->success(['id' => $review->uuid, 'status' => 'hidden']);
    }
}
