<?php

namespace App\Http\Controllers\Api\V1\Course;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Services\Certificate\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SRS 3.3 Student "Enroll in courses · Track progress".
 * Lesson completion drives Enrollment.progress_percentage automatically.
 */
class EnrollmentController extends Controller
{
    public function __construct(protected CertificateService $certificates) {}

    /** POST /api/v1/courses/{course:uuid}/enroll */
    public function enroll(Course $course, Request $request): JsonResponse
    {
        if ($course->status !== 'published') {
            return $this->error('Course is not open for enrollment.', 422);
        }

        $user = $request->user();

        // SRS Module 12: paid courses require a paid invoice before enrollment.
        // Return 402 Payment Required with the invoice UUID so the frontend can
        // route to /checkout — free courses fall straight through.
        if (!$course->isFree()) {
            $existingEnrollment = Enrollment::where('user_id', $user->id)
                ->where('course_id', $course->id)->first();

            if (!$existingEnrollment) {
                // Look up a paid invoice for this course + user.
                $paidInvoice = Invoice::where('billed_user_id', $user->id)
                    ->where('subject_type', Course::class)
                    ->where('subject_id', $course->id)
                    ->where('status', Invoice::STATUS_PAID)
                    ->latest('id')
                    ->first();

                if (!$paidInvoice) {
                    // Auto-issue (or reuse) an open invoice + return checkout details.
                    $invoiceService = app(\App\Services\Payment\InvoiceService::class);
                    $invoice = $invoiceService->issueForCourse($user, $course);

                    return $this->error('Payment required to enroll in this course.', 402, [
                        'invoice_uuid' => $invoice->uuid,
                        'invoice_number' => $invoice->invoice_number,
                        'total_tzs' => $invoice->total_tzs,
                        'currency' => $invoice->currency,
                    ]);
                }
            }
        }

        $enrollment = Enrollment::firstOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id],
            ['enrolled_at' => now(), 'progress_percentage' => 0]
        );

        return $this->success($this->transform($enrollment->fresh('course')), 'Enrolled', 201);
    }

    /** GET /api/v1/student/my-enrollments */
    public function myEnrollments(Request $request): JsonResponse
    {
        $enrollments = Enrollment::with(['course:id,uuid,slug,title,category,thumbnail_url,duration_hours,price_tzs,status'])
            ->where('user_id', $request->user()->id)
            ->latest('enrolled_at')
            ->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $enrollments->getCollection()->map(fn ($e) => $this->transform($e)),
            'meta' => [
                'current_page' => $enrollments->currentPage(),
                'last_page' => $enrollments->lastPage(),
                'per_page' => $enrollments->perPage(),
                'total' => $enrollments->total(),
            ],
        ]);
    }

    /** POST /api/v1/lessons/{lesson:uuid}/mark-complete */
    public function markLessonComplete(Lesson $lesson, Request $request): JsonResponse
    {
        $user = $request->user();
        $course = $lesson->module->course;

        // Must be enrolled
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();
        if (!$enrollment) {
            return $this->error('Enroll in the course first.', 422);
        }

        DB::table('lesson_completions')->updateOrInsert(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['completed_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );

        $this->recalculateProgress($enrollment);

        return $this->success([
            'progress_percentage' => (float) $enrollment->fresh()->progress_percentage,
            'completed' => $enrollment->fresh()->completed_at !== null,
        ], 'Lesson marked complete');
    }

    private function recalculateProgress(Enrollment $enrollment): void
    {
        $totalLessons = DB::table('lessons')
            ->join('course_modules', 'course_modules.id', '=', 'lessons.course_module_id')
            ->where('course_modules.course_id', $enrollment->course_id)
            ->whereNull('lessons.deleted_at')
            ->whereNull('course_modules.deleted_at')
            ->count();

        if ($totalLessons === 0) {
            $enrollment->update(['progress_percentage' => 0]);
            return;
        }

        $completedLessons = DB::table('lesson_completions')
            ->join('lessons', 'lessons.id', '=', 'lesson_completions.lesson_id')
            ->join('course_modules', 'course_modules.id', '=', 'lessons.course_module_id')
            ->where('lesson_completions.user_id', $enrollment->user_id)
            ->where('course_modules.course_id', $enrollment->course_id)
            ->count();

        $pct = round(($completedLessons / $totalLessons) * 100, 2);

        $wasComplete = $enrollment->completed_at !== null;
        $enrollment->update([
            'progress_percentage' => $pct,
            'completed_at' => $pct >= 100 ? ($enrollment->completed_at ?? now()) : null,
        ]);

        // SRS Module 10 — auto-issue a certificate the first time a course hits 100%
        // AND (if a final assessment quiz is defined) the student has passed it.
        // CertificateService is idempotent — safe if called twice.
        if ($pct >= 100 && !$wasComplete) {
            try {
                $this->certificates->issueForCourse(
                    $enrollment->user,
                    $enrollment->course()->first(),
                );
            } catch (\Throwable $e) {
                Log::warning('Course completion cert issue failed', [
                    'enrollment_id' => $enrollment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function transform(Enrollment $e): array
    {
        return [
            'uuid' => $e->uuid,
            'progress_percentage' => (float) $e->progress_percentage,
            'enrolled_at' => $e->enrolled_at?->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(),
            'course' => $e->course ? [
                'uuid' => $e->course->uuid,
                'slug' => $e->course->slug,
                'title' => $e->course->title,
                'category' => $e->course->category,
                'thumbnail_url' => $this->signedUrl($e->course->thumbnail_url),
                'duration_hours' => $e->course->duration_hours,
                'price_tzs' => $e->course->price_tzs,
                'is_free' => $e->course->isFree(),
            ] : null,
        ];
    }

    private function signedUrl(?string $url): ?string
    {
        if (!$url) return null;
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) return $url;

        return Cache::remember('signed_url_' . md5($url), now()->addHours(23), function () use ($url) {
            try {
                $bucket = config('filesystems.disks.s3.bucket', '');
                $region = config('filesystems.disks.s3.region', '');
                $path = null;
                foreach ([
                    "https://{$bucket}.s3.{$region}.amazonaws.com/",
                    "https://{$bucket}.s3.amazonaws.com/",
                    "https://s3.{$region}.amazonaws.com/{$bucket}/",
                ] as $prefix) {
                    if (str_starts_with($url, $prefix)) {
                        $path = urldecode(substr($url, strlen($prefix)));
                        break;
                    }
                }
                if (!$path) return $url;
                return Storage::disk(config('filesystems.default', 's3'))->temporaryUrl($path, now()->addDay());
            } catch (\Throwable) {
                return $url;
            }
        });
    }
}
