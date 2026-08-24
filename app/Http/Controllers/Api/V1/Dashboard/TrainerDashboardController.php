<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * SRS Module 11 — Trainer Dashboard.
 *
 *   headline: Active Courses · Total Courses · Student Count · Avg Quiz Score
 *   per_course_students:  bar chart data — students per course (top 10)
 *   quiz_performance:     per quiz — attempts, pass rate, avg score (bar)
 *   category_distribution:course count grouped by category (donut)
 *   recent_activity:      recent enrollments + attempts on trainer's courses
 *
 * Notes:
 *  - avg_quiz_score returns null when there are no attempts (frontend shows "—").
 *  - Cached per-user for 60s.
 *  - ?days=7|30|90|365 filters trend window; default all-time.
 */
class TrainerDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = $this->window($request);
        $cacheKey = "dash:trainer:{$user->id}:d{$days}";

        $payload = Cache::remember($cacheKey, 60, function () use ($user, $days) {
            return $this->build($user->id, $days);
        });

        return $this->success($payload);
    }

    private function build(int $trainerId, ?int $days): array
    {
        $since = $days ? Carbon::now()->subDays($days) : null;

        $courseIds = Course::where('instructor_id', $trainerId)->pluck('id')->all();
        $publishedCount = Course::where('instructor_id', $trainerId)
            ->where('status', 'published')
            ->count();
        $myQuizIds = Quiz::where('created_by', $trainerId)->pluck('id')->all();

        $studentCount = empty($courseIds) ? 0 : (int) Enrollment::whereIn('course_id', $courseIds)
            ->when($since, fn ($q) => $q->where('enrolled_at', '>=', $since))
            ->distinct('user_id')
            ->count('user_id');

        // avg_score: null when no attempts, not 0 (SRS "Quiz Performance").
        $attemptsAgg = empty($myQuizIds) ? null : QuizAttempt::whereIn('quiz_id', $myQuizIds)
            ->whereIn('status', ['completed', 'expired'])
            ->when($since, fn ($q) => $q->where('completed_at', '>=', $since))
            ->selectRaw('COUNT(*) as c, AVG(percentage) as avg_pct')
            ->first();
        $avgQuizScore = ($attemptsAgg && $attemptsAgg->c > 0)
            ? round((float) $attemptsAgg->avg_pct, 1)
            : null;

        // ── Per-course student count (top 10) ────────────────────
        $perCourseQ = DB::table('enrollments')
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->where('courses.instructor_id', $trainerId)
            ->whereNull('courses.deleted_at')
            ->select(
                'courses.uuid as id',
                'courses.title',
                'courses.status',
                DB::raw('COUNT(DISTINCT enrollments.user_id) as students'),
                DB::raw('ROUND(AVG(enrollments.progress_percentage), 1) as avg_progress'),
            )
            ->groupBy('courses.uuid', 'courses.title', 'courses.status')
            ->orderByDesc('students')
            ->limit(10);
        if ($since) $perCourseQ->where('enrollments.enrolled_at', '>=', $since);
        $perCourse = $perCourseQ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'status' => $r->status,
                'students' => (int) $r->students,
                'avg_progress' => (float) $r->avg_progress,
            ]);

        // ── Per-quiz performance ─────────────────────────────────
        $quizStats = DB::table('quizzes')
            ->leftJoin('quiz_attempts', function ($j) use ($since) {
                $j->on('quiz_attempts.quiz_id', '=', 'quizzes.id')
                  ->whereIn('quiz_attempts.status', ['completed', 'expired']);
                if ($since) {
                    $j->where('quiz_attempts.completed_at', '>=', $since);
                }
            })
            ->where('quizzes.created_by', $trainerId)
            ->whereNull('quizzes.deleted_at')
            ->select(
                'quizzes.uuid as id',
                'quizzes.name',
                'quizzes.mode',
                'quizzes.exam_type',
                'quizzes.status',
                DB::raw('COUNT(quiz_attempts.id) as attempts'),
                DB::raw('AVG(quiz_attempts.percentage) as avg_score'),
                DB::raw('SUM(CASE WHEN quiz_attempts.passed = 1 THEN 1 ELSE 0 END) as passes'),
            )
            ->groupBy('quizzes.uuid', 'quizzes.name', 'quizzes.mode', 'quizzes.exam_type', 'quizzes.status')
            ->orderByDesc('attempts')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'mode' => $row->mode,
                'exam_type' => $row->exam_type,
                'status' => $row->status,
                'attempts' => (int) $row->attempts,
                'passes' => (int) $row->passes,
                'pass_rate' => $row->attempts > 0
                    ? round((int) $row->passes / (int) $row->attempts * 100, 1)
                    : 0.0,
                'avg_score' => $row->attempts > 0 && $row->avg_score !== null
                    ? round((float) $row->avg_score, 1)
                    : null,
            ]);

        // ── Category distribution ────────────────────────────────
        $categoryDist = Course::where('instructor_id', $trainerId)
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(NULLIF(category, ""), "uncategorized") as category, COUNT(*) as count')
            ->groupBy('category')
            ->get()
            ->map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->count]);

        // ── Recent activity ──────────────────────────────────────
        $recentEnrollments = empty($courseIds) ? collect() :
            Enrollment::with(['course:id,title', 'user:id,email'])
                ->whereIn('course_id', $courseIds)
                ->when($since, fn ($q) => $q->where('enrolled_at', '>=', $since))
                ->latest('enrolled_at')
                ->limit(6)
                ->get()
                ->map(fn ($e) => [
                    'type' => 'enrollment',
                    'title' => $e->course?->title,
                    'value' => $e->user?->email,
                    'at' => $e->enrolled_at?->toIso8601String(),
                ]);

        $recentAttempts = empty($myQuizIds) ? collect() :
            QuizAttempt::with(['quiz:id,name', 'user:id,email'])
                ->whereIn('quiz_id', $myQuizIds)
                ->whereIn('status', ['completed', 'expired'])
                ->when($since, fn ($q) => $q->where('completed_at', '>=', $since))
                ->latest('completed_at')
                ->limit(6)
                ->get()
                ->map(fn ($a) => [
                    'type' => 'attempt',
                    'title' => $a->quiz?->name,
                    'value' => "{$a->user?->email} · {$a->percentage}%",
                    'passed' => (bool) $a->passed,
                    'at' => $a->completed_at?->toIso8601String(),
                ]);

        $recentActivity = $recentEnrollments
            ->concat($recentAttempts)
            ->sortByDesc('at')
            ->take(10)
            ->values();

        return [
            'window_days' => $days,
            'headline' => [
                'active_courses' => $publishedCount,
                'total_courses' => count($courseIds),
                'student_count' => $studentCount,
                'avg_quiz_score_percent' => $avgQuizScore,
            ],
            'per_course_students' => $perCourse,
            'quiz_performance' => $quizStats,
            'category_distribution' => $categoryDist,
            'recent_activity' => $recentActivity,
        ];
    }

    private function window(Request $request): ?int
    {
        $d = (int) $request->query('days', 0);
        return in_array($d, [7, 30, 90, 365], true) ? $d : null;
    }

    /**
     * SRS Progress Reports drill-down: per course roster.
     * GET /trainer/courses/{course_uuid}/report
     */
    public function courseReport(Request $request, string $courseUuid): JsonResponse
    {
        $trainerId = $request->user()->id;
        $course = Course::where('uuid', $courseUuid)
            ->where('instructor_id', $trainerId)
            ->first();
        if (!$course) return $this->error('Course not found or not yours.', 404);

        $roster = Enrollment::with(['user:id,uuid,email', 'user.profile:user_id,first_name,last_name,department'])
            ->where('course_id', $course->id)
            ->latest('enrolled_at')
            ->get()
            ->map(function ($e) use ($course) {
                $bestAttempt = null;
                if ($course->final_assessment_quiz_id) {
                    $bestAttempt = QuizAttempt::where('user_id', $e->user_id)
                        ->where('quiz_id', $course->final_assessment_quiz_id)
                        ->whereIn('status', ['completed', 'expired'])
                        ->orderByDesc('percentage')
                        ->first();
                }
                return [
                    'user_id' => $e->user?->uuid,
                    'email' => $e->user?->email,
                    'name' => trim(($e->user->profile->first_name ?? '') . ' ' . ($e->user->profile->last_name ?? '')) ?: $e->user?->email,
                    'department' => $e->user->profile->department ?? null,
                    'progress' => (float) $e->progress_percentage,
                    'enrolled_at' => $e->enrolled_at?->toIso8601String(),
                    'completed_at' => $e->completed_at?->toIso8601String(),
                    'best_score' => $bestAttempt ? (float) $bestAttempt->percentage : null,
                    'passed_final' => $bestAttempt ? (bool) $bestAttempt->passed : null,
                ];
            });

        return $this->success([
            'course' => [
                'id' => $course->uuid,
                'title' => $course->title,
                'status' => $course->status,
                'passing_score' => $course->passing_score ?? null,
            ],
            'roster' => $roster,
            'headline' => [
                'enrolled' => $roster->count(),
                'completed' => $roster->where('completed_at', '!=', null)->count(),
                'avg_progress' => $roster->count() > 0
                    ? round($roster->avg('progress'), 1) : 0.0,
                'avg_score' => $roster->whereNotNull('best_score')->count() > 0
                    ? round($roster->whereNotNull('best_score')->avg('best_score'), 1) : null,
            ],
        ]);
    }
}
