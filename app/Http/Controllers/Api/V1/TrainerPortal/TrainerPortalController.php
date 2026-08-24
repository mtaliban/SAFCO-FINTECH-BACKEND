<?php

namespace App\Http\Controllers\Api\V1\TrainerPortal;

use App\Http\Controllers\Controller;
use App\Models\TrainerCertification;
use App\Models\TrainerExperience;
use App\Models\TrainerProfile;
use App\Models\TrainerQualification;
use App\Services\TrainerPortal\TrainerProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * SRS Module 13 — Trainer self-management.
 *
 * Every write here operates on the authenticated trainer's OWN profile —
 * there is no path to touch another trainer's data.
 */
class TrainerPortalController extends Controller
{
    // Allowed proof-doc uploads: PDF, images. Max 8 MB.
    private const PROOF_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    private const PROOF_MAX_KB = 8192;
    // Cap items per trainer to prevent spam of the admin verification queue.
    private const MAX_ITEMS_PER_TYPE = 20;

    public function __construct(private readonly TrainerProfileService $profiles) {}

    /** GET /trainer/portal/profile */
    public function myProfile(Request $request): JsonResponse
    {
        $tp = $this->profiles->getOrCreateFor($request->user());
        $tp->load([
            'qualifications',
            'certifications',
            'experiences',
            // SRS M13 — trainer's own "Courses Delivered" section (all statuses,
            // so the trainer sees drafts too — public view filters to published).
            'courses' => fn ($q) => $q->withCount('enrollments')->latest(),
        ]);

        return $this->success([
            'profile' => $this->serializeProfile($tp),
            'qualifications' => $tp->qualifications->map(fn ($q) => $this->serializeQualification($q)),
            'certifications' => $tp->certifications->map(fn ($c) => $this->serializeCertification($c)),
            'experiences' => $tp->experiences->map(fn ($e) => $this->serializeExperience($e)),
            'courses' => $tp->courses->map(fn ($c) => [
                'id' => $c->uuid,
                'title' => $c->title,
                'slug' => $c->slug,
                'category' => $c->category,
                'level' => $c->level,
                'status' => $c->status,
                'thumbnail_url' => $c->thumbnail_url,
                'enrollments_count' => (int) ($c->enrollments_count ?? 0),
                'price_tzs' => $c->price_tzs,
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /** PATCH /trainer/portal/profile */
    public function updateProfile(Request $request): JsonResponse
    {
        $tp = $this->profiles->getOrCreateFor($request->user());

        $data = $request->validate([
            'headline' => ['sometimes', 'nullable', 'string', 'max:180'],
            'bio_long' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'years_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:80'],
            'expertise_areas' => ['sometimes', 'nullable', 'array', 'max:20'],
            'expertise_areas.*' => ['string', 'max:60'],
            'teaching_languages' => ['sometimes', 'nullable', 'array', 'max:10'],
            'teaching_languages.*' => ['string', 'max:10'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'hourly_rate_tzs' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'availability_status' => ['sometimes', Rule::in([
                TrainerProfile::AVAILABILITY_AVAILABLE,
                TrainerProfile::AVAILABILITY_BUSY,
                TrainerProfile::AVAILABILITY_UNAVAILABLE,
            ])],
            'is_public' => ['sometimes', 'boolean'],
            'accepts_direct_inquiries' => ['sometimes', 'boolean'],
            'public_email' => ['sometimes', 'nullable', 'email', 'max:180'],
        ]);
        $tp->update($data);
        return $this->success(['profile' => $this->serializeProfile($tp->fresh())]);
    }

    // ── Qualifications CRUD ────────────────────────────────────────

    public function storeQualification(Request $request): JsonResponse
    {
        $tp = $this->profiles->getOrCreateFor($request->user());

        // Enforce per-trainer cap so a bad actor can't spam admin queue.
        if ($tp->qualifications()->count() >= self::MAX_ITEMS_PER_TYPE) {
            return $this->error('Maximum of ' . self::MAX_ITEMS_PER_TYPE . ' qualifications reached. Remove one to add another.', 422);
        }

        $data = $request->validate([
            'institution' => ['required', 'string', 'max:200'],
            'degree' => ['required', 'string', 'max:150'],
            'field_of_study' => ['nullable', 'string', 'max:150'],
            'start_year' => ['nullable', 'integer', 'min:1950', 'max:' . (int) date('Y')],
            'end_year' => ['nullable', 'integer', 'min:1950', 'max:' . ((int) date('Y') + 10)],
            'proof' => ['nullable', 'file', 'max:' . self::PROOF_MAX_KB, 'mimetypes:' . implode(',', self::PROOF_MIME_TYPES)],
        ]);
        try {
            $file = $this->storeProof($request, $tp, 'qualifications');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $q = TrainerQualification::create(array_merge(
            \Arr::except($data, ['proof']),
            ['trainer_profile_id' => $tp->id],
            $file ?? []
        ));
        return $this->success($this->serializeQualification($q), 'Qualification added', 201);
    }

    public function deleteQualification(TrainerQualification $qualification, Request $request): JsonResponse
    {
        $this->ownOrDeny($qualification->profile, $request);
        if ($qualification->proof_file_path) {
            Storage::disk('local')->delete($qualification->proof_file_path);
        }
        $qualification->delete();
        return $this->success(null, 'Qualification removed');
    }

    // ── Certifications CRUD ────────────────────────────────────────

    public function storeCertification(Request $request): JsonResponse
    {
        $tp = $this->profiles->getOrCreateFor($request->user());

        if ($tp->certifications()->count() >= self::MAX_ITEMS_PER_TYPE) {
            return $this->error('Maximum of ' . self::MAX_ITEMS_PER_TYPE . ' certifications reached.', 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'issuer' => ['required', 'string', 'max:200'],
            'credential_id' => ['nullable', 'string', 'max:150'],
            'verification_url' => ['nullable', 'url', 'max:500'],
            'issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expiry_date' => ['nullable', 'date', 'after:issue_date'],
            'proof' => ['nullable', 'file', 'max:' . self::PROOF_MAX_KB, 'mimetypes:' . implode(',', self::PROOF_MIME_TYPES)],
        ]);
        try {
            $file = $this->storeProof($request, $tp, 'certifications');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $c = TrainerCertification::create(array_merge(
            \Arr::except($data, ['proof']),
            ['trainer_profile_id' => $tp->id],
            $file ?? []
        ));
        return $this->success($this->serializeCertification($c), 'Certification added', 201);
    }

    public function deleteCertification(TrainerCertification $certification, Request $request): JsonResponse
    {
        $this->ownOrDeny($certification->profile, $request);
        if ($certification->proof_file_path) {
            Storage::disk('local')->delete($certification->proof_file_path);
        }
        $certification->delete();
        return $this->success(null, 'Certification removed');
    }

    // ── Experiences CRUD ────────────────────────────────────────

    public function storeExperience(Request $request): JsonResponse
    {
        $tp = $this->profiles->getOrCreateFor($request->user());

        if ($tp->experiences()->count() >= self::MAX_ITEMS_PER_TYPE) {
            return $this->error('Maximum of ' . self::MAX_ITEMS_PER_TYPE . ' experience entries reached.', 422);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'company' => ['required', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:200'],
            'start_date' => ['required', 'date', 'before_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'description' => ['nullable', 'string', 'max:3000'],
        ]);
        $e = TrainerExperience::create(array_merge($data, ['trainer_profile_id' => $tp->id]));
        return $this->success($this->serializeExperience($e), 'Experience added', 201);
    }

    public function deleteExperience(TrainerExperience $experience, Request $request): JsonResponse
    {
        $this->ownOrDeny($experience->profile, $request);
        $experience->delete();
        return $this->success(null, 'Experience removed');
    }

    // ── Proof-file download (signed URL, expires in 10 min) ─────

    public function proofUrl(string $type, string $uuid, Request $request): JsonResponse
    {
        [$model, $item] = $this->resolveProof($type, $uuid);
        $this->authorizeProof($item, $request);

        if (!$item->proof_file_path) {
            return $this->error('No proof uploaded for this item.', 404);
        }

        // Bind the URL to the requesting user so it can't be forwarded to a
        // random person. Also short TTL (10 min) as second defense.
        $url = URL::temporarySignedRoute('trainer.proof.download', now()->addMinutes(10), [
            'type' => $type,
            'uuid' => $uuid,
            'audience' => $request->user()->id,
        ]);
        return $this->success(['download_url' => $url, 'expires_in_seconds' => 600]);
    }

    public function proofDownload(string $type, string $uuid, Request $request): Response
    {
        if (!$request->hasValidSignature()) abort(403, 'Expired or invalid link.');

        // The URL is tied to the user who requested it. The download route also
        // requires auth (see routes/api.php), so we can confirm the caller matches
        // the audience claim baked into the signature.
        $audience = (int) $request->query('audience', 0);
        if (!$request->user() || $request->user()->id !== $audience) {
            abort(403, 'This download link is bound to a different account.');
        }

        [, $item] = $this->resolveProof($type, $uuid);
        if (!$item->proof_file_path || !Storage::disk('local')->exists($item->proof_file_path)) {
            abort(404);
        }
        return response(
            Storage::disk('local')->get($item->proof_file_path),
            200,
            [
                'Content-Type' => $item->proof_mime_type ?? 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . ($item->proof_file_name ?? 'proof') . '"',
            ]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function storeProof(Request $request, TrainerProfile $tp, string $bucket): ?array
    {
        if (!$request->hasFile('proof')) return null;
        $file = $request->file('proof');

        // Defense in depth: verify magic bytes before we accept the file.
        \App\Services\TrainerPortal\FileValidator::assertSafeProofFile($file);

        $path = $file->store("trainer-proofs/{$tp->uuid}/{$bucket}", 'local');
        return [
            'proof_file_path' => $path,
            'proof_file_name' => $file->getClientOriginalName(),
            'proof_file_size' => $file->getSize(),
            'proof_mime_type' => $file->getMimeType(),
        ];
    }

    private function ownOrDeny(TrainerProfile $profile, Request $request): void
    {
        $user = $request->user();
        if ($profile->user_id !== $user->id && !$user->hasRole('system_admin')) {
            abort(403, 'Not your profile.');
        }
    }

    private function resolveProof(string $type, string $uuid): array
    {
        return match ($type) {
            'qualifications' => [TrainerQualification::class,
                TrainerQualification::where('uuid', $uuid)->firstOrFail()],
            'certifications' => [TrainerCertification::class,
                TrainerCertification::where('uuid', $uuid)->firstOrFail()],
            default => abort(404, 'Unknown proof type'),
        };
    }

    private function authorizeProof($item, Request $request): void
    {
        $user = $request->user();
        if (!$user) abort(401);
        // Signed URL routes don't require auth; but the URL-issuing endpoint does.
        // Admin can always view; trainer can view own.
        if ($user->hasRole('system_admin')) return;
        if ($item->profile->user_id === $user->id) return;
        abort(403, 'Not authorized to view this proof.');
    }

    // ── Serializers ───────────────────────────────────────────────

    private function serializeProfile(TrainerProfile $tp): array
    {
        return [
            'slug' => $tp->public_slug,
            'headline' => $tp->headline,
            'bio_long' => $tp->bio_long,
            'years_experience' => $tp->years_experience,
            'expertise_areas' => $tp->expertise_areas ?? [],
            'teaching_languages' => $tp->teaching_languages ?? [],
            'timezone' => $tp->timezone,
            'hourly_rate_tzs' => $tp->hourly_rate_tzs,
            'availability_status' => $tp->availability_status,
            'is_public' => $tp->is_public,
            'is_verified' => $tp->is_verified,
            'verified_at' => $tp->verified_at?->toIso8601String(),
            'rating_avg' => $tp->rating_avg !== null ? (float) $tp->rating_avg : null,
            'rating_count' => $tp->rating_count,
            'students_taught' => $tp->students_taught_count,
            'accepts_direct_inquiries' => $tp->accepts_direct_inquiries,
            'public_email' => $tp->public_email,
        ];
    }

    private function serializeQualification(TrainerQualification $q): array
    {
        return [
            'id' => $q->uuid,
            'institution' => $q->institution,
            'degree' => $q->degree,
            'field_of_study' => $q->field_of_study,
            'start_year' => $q->start_year,
            'end_year' => $q->end_year,
            'verification_status' => $q->verification_status,
            'rejection_reason' => $q->rejection_reason,
            'has_proof' => !empty($q->proof_file_path),
            'proof_file_name' => $q->proof_file_name,
        ];
    }

    private function serializeCertification(TrainerCertification $c): array
    {
        return [
            'id' => $c->uuid,
            'name' => $c->name,
            'issuer' => $c->issuer,
            'credential_id' => $c->credential_id,
            'verification_url' => $c->verification_url,
            'issue_date' => $c->issue_date?->toDateString(),
            'expiry_date' => $c->expiry_date?->toDateString(),
            'is_expired' => $c->isExpired(),
            'is_expiring_soon' => $c->isExpiringSoon(),
            'verification_status' => $c->verification_status,
            'rejection_reason' => $c->rejection_reason,
            'has_proof' => !empty($c->proof_file_path),
            'proof_file_name' => $c->proof_file_name,
        ];
    }

    private function serializeExperience(TrainerExperience $e): array
    {
        return [
            'id' => $e->uuid,
            'title' => $e->title,
            'company' => $e->company,
            'location' => $e->location,
            'start_date' => $e->start_date?->toDateString(),
            'end_date' => $e->end_date?->toDateString(),
            'is_current' => $e->isCurrent(),
            'duration_years' => $e->durationYears(),
            'description' => $e->description,
        ];
    }
}
