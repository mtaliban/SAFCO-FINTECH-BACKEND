<?php

namespace App\Services\Certificate;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\QuizAttempt;
use App\Models\User;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * SRS Module 10 — Certificate lifecycle service.
 *
 *   issueForAttempt        — when a passed final_certification exam attempt completes
 *   issueForCourse         — when course 100% completed (with any final assessment passed)
 *   verifyByCertNumber     — public verification (returns status + snapshot data)
 *   revoke                 — admin action, keeps the row for audit but flips status
 *   renderQrSvg            — QR SVG bytes (encodes the public verify URL)
 *   generateCertNumber     — unique SAFCO-YYYY-XXXXXXXX identifier
 *   buildVerificationHash  — SHA256(cert_number|user_id|course_id|APP_KEY) for anti-forgery
 */
class CertificateService
{
    /** Public verify URL that the QR code encodes. Points to the frontend page. */
    public function verificationUrl(string $certNumber): string
    {
        $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:3002'), '/');
        return "{$frontend}/verify/certificate/{$certNumber}";
    }

    /* ---------------------------------------------------------------- *
     * Issuance
     * ---------------------------------------------------------------- */

    /**
     * Issue (or return existing) certificate for a passed final_certification attempt.
     * Idempotent: the DB unique(user_id, course_id) guarantees no duplicates.
     */
    public function issueForAttempt(QuizAttempt $attempt): ?Certificate
    {
        if (!$attempt->passed) return null;

        $quiz = $attempt->quiz;
        // Only final_certification quizzes issue a certificate at the exam level
        if ($quiz->exam_type !== 'final_certification') return null;

        // Discover the parent course via the quiz's course_module linkage
        $course = null;
        if ($quiz->course_module_id) {
            $course = optional($quiz->courseModule)->course;
        }
        // Fallback: any course whose final_assessment_quiz_id points at this quiz
        $course ??= Course::where('final_assessment_quiz_id', $quiz->id)->first();
        if (!$course) return null;

        return $this->issueCertificate(
            user: $attempt->user,
            course: $course,
            completionDate: $attempt->completed_at?->toDateString() ?? now()->toDateString(),
            scorePercentage: (float) $attempt->percentage,
            attemptId: $attempt->id,
        );
    }

    /**
     * Issue (or return existing) certificate for a fully-completed course.
     * Per SRS Module 10 — cert issuance is gated on "Score ≥ Pass Mark", so a course
     * MUST have a `final_assessment_quiz_id` AND that quiz must be passed by this user.
     * Returns null (no cert) if either condition isn't met.
     */
    public function issueForCourse(User $user, Course $course, ?QuizAttempt $attempt = null): ?Certificate
    {
        // Hard requirement: course must define a final assessment
        if (!$course->final_assessment_quiz_id) return null;

        // Load the best passed attempt so we can snapshot its score onto the cert
        $bestAttempt = $attempt ?? QuizAttempt::where('user_id', $user->id)
            ->where('quiz_id', $course->final_assessment_quiz_id)
            ->where('passed', true)
            ->orderByDesc('percentage')
            ->first();

        if (!$bestAttempt) return null;   // never passed the final assessment

        return $this->issueCertificate(
            user: $user,
            course: $course,
            completionDate: $bestAttempt->completed_at?->toDateString() ?? now()->toDateString(),
            scorePercentage: (float) $bestAttempt->percentage,
            attemptId: $bestAttempt->id,
        );
    }

    /** Idempotent — race-safe via unique constraint. */
    protected function issueCertificate(
        User $user,
        Course $course,
        string $completionDate,
        ?float $scorePercentage,
        ?int $attemptId,
    ): Certificate {
        return DB::transaction(function () use ($user, $course, $completionDate, $scorePercentage, $attemptId) {
            // Fast path: return existing so callers can safely retry
            $existing = Certificate::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();
            if ($existing) return $existing;

            $certNumber = $this->generateCertNumber();
            $cert = Certificate::create([
                'cert_number' => $certNumber,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'quiz_attempt_id' => $attemptId,
                'student_name_snapshot' => $this->studentName($user),
                'course_title_snapshot' => $course->title,
                'completion_date' => $completionDate,
                'issued_at' => now(),
                'score_percentage' => $scorePercentage,
                'verification_hash' => $this->buildVerificationHash($certNumber, $user->id, $course->id),
                'status' => Certificate::STATUS_ACTIVE,
            ]);

            return $cert;
        });
    }

    protected function studentName(User $user): string
    {
        $profile = $user->profile;
        if ($profile && trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')) !== '') {
            return trim($profile->first_name . ' ' . $profile->last_name);
        }
        return $user->email;
    }

    /* ---------------------------------------------------------------- *
     * Cert number + verification hash
     * ---------------------------------------------------------------- */

    /**
     * SAFCO-YYYY-XXXXXXXX — year prefix + 8 uppercase base32-ish chars.
     * Retries on ultra-rare collisions.
     */
    public function generateCertNumber(): string
    {
        $year = date('Y');
        for ($i = 0; $i < 20; $i++) {
            $random = strtoupper(Str::random(8));
            // Remove ambiguous chars (0/O, 1/I/L)
            $random = strtr($random, ['0' => 'X', 'O' => 'Y', '1' => 'Z', 'I' => 'W', 'L' => 'K']);
            $number = "SAFCO-{$year}-{$random}";
            if (!Certificate::where('cert_number', $number)->exists()) return $number;
        }
        throw new \RuntimeException('Failed to generate unique certificate number after 20 attempts.');
    }

    /**
     * Build the anti-forgery hash.
     *
     * We prefer a dedicated CERTIFICATE_SIGNING_KEY so that APP_KEY rotation
     * (which invalidates all sessions/cookies) does NOT retroactively brick every
     * issued certificate. Falls back to APP_KEY for greenfield installs — but
     * production deployers should set CERTIFICATE_SIGNING_KEY once and treat it
     * as write-once (rotating it means every existing cert reads as "tampered").
     */
    public function buildVerificationHash(string $certNumber, int $userId, int $courseId): string
    {
        $secret = env('CERTIFICATE_SIGNING_KEY') ?: config('app.key');
        return hash('sha256', "{$certNumber}|{$userId}|{$courseId}|{$secret}");
    }

    /* ---------------------------------------------------------------- *
     * Public verification
     * ---------------------------------------------------------------- */

    /**
     * Returns [status, cert|null].
     *   status: 'valid' | 'revoked' | 'not_found' | 'tampered'
     * When 'valid' or 'revoked', a matching cert is returned so the UI
     * can render the snapshot data. When 'tampered' the cert exists but
     * its stored hash doesn't match what we'd re-compute (row was mutated
     * outside the app).
     */
    public function verifyByCertNumber(string $certNumber): array
    {
        $cert = Certificate::with(['course:id,uuid,title,category', 'user:id,uuid,email'])
            ->where('cert_number', $certNumber)
            ->first();

        if (!$cert) return ['status' => 'not_found', 'certificate' => null];

        $expected = $this->buildVerificationHash($cert->cert_number, $cert->user_id, $cert->course_id);
        if (!hash_equals($cert->verification_hash, $expected)) {
            return ['status' => 'tampered', 'certificate' => $cert];
        }

        return [
            'status' => $cert->isRevoked() ? 'revoked' : 'valid',
            'certificate' => $cert,
        ];
    }

    /* ---------------------------------------------------------------- *
     * Revocation
     * ---------------------------------------------------------------- */

    public function revoke(Certificate $cert, User $admin, string $reason): Certificate
    {
        $before = [
            'status' => $cert->status,
            'revoked_at' => $cert->revoked_at?->toIso8601String(),
            'revoked_reason' => $cert->revoked_reason,
        ];

        $cert->update([
            'status' => Certificate::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            'revoked_by' => $admin->id,
        ]);

        // Audit trail via spatie/laravel-activitylog
        activity('certificates')
            ->causedBy($admin)
            ->performedOn($cert)
            ->withProperties([
                'cert_number' => $cert->cert_number,
                'student_user_id' => $cert->user_id,
                'reason' => $reason,
                'before' => $before,
            ])
            ->log('certificate_revoked');

        return $cert->fresh();
    }

    /* ---------------------------------------------------------------- *
     * QR SVG
     * ---------------------------------------------------------------- */

    /** Renders a SVG QR code that encodes the public verification URL. */
    public function renderQrSvg(Certificate $cert, int $size = 320): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 2),
            new SvgImageBackEnd(),
        );
        $writer = new Writer($renderer);
        return $writer->writeString($this->verificationUrl($cert->cert_number));
    }
}
