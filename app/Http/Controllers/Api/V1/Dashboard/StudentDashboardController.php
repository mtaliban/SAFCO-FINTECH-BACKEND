<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * SRS Module 11 — Student Dashboard.
 *
 *   headline: Courses Enrolled · Courses Completed · Average Score · Certificates Earned
 *   enrollments:    per-course progress (progress-bar list)
 *   score_trend:    last 10 completed attempts (line chart)
 *   recent_activity:latest attempts + certificates for the timeline
 *
 * Notes:
 *  - avg_score returns null when there are no attempts (frontend shows "—").
 *  - Cached per-user for 60s.
 *  - ?days=7|30|90|365 filters trend window.
 */
class StudentDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = $this->window($request);
        $cacheKey = "dash:student:{$user->id}:d{$days}";

        $payload = Cache::remember($cacheKey, 60, function () use ($user, $days) {
            return $this->build($user->id, $days);
        });

        return $this->success($payload);
    }

    private function build(int $userId, ?int $days): array
    {
        $since = $days ? Carbon::now()->subDays($days) : null;

        // ── Headline stats ────────────────────────────────────────
        $enrolledCount  = Enrollment::where('user_id', $userId)
            ->when($since, fn ($q) => $q->where('enrolled_at', '>=', $since))
            ->count();
        $completedCount = Enrollment::where('user_id', $userId)
            ->when($since, fn ($q) => $q->where('completed_at', '>=', $since))
            ->whereNotNull('completed_at')
            ->count();

        $attemptsAgg = QuizAttempt::where('user_id', $userId)
            ->whereIn('status', ['completed', 'expired'])
            ->when($since, fn ($q) => $q->where('completed_at', '>=', $since))
            ->selectRaw('COUNT(*) as c, AVG(percentage) as avg_pct')
            ->first();
        $avgScore = ($attemptsAgg && $attemptsAgg->c > 0)
            ? round((float) $attemptsAgg->avg_pct, 1)
            : null;

        $certsEarned = Certificate::where('user_id', $userId)
            ->where('status', Certificate::STATUS_ACTIVE)
            ->when($since, fn ($q) => $q->where('issued_at', '>=', $since))
            ->count();

        // ── Enrollments (progress bars) ──────────────────────────
        $enrollments = Enrollment::with(['course:id,uuid,title,category,thumbnail_url'])
            ->where('user_id', $userId)
            ->latest('enrolled_at')
            ->limit(10)
            ->get()
            ->map(fn ($e) => [
                'course_id' => $e->course?->uuid,
                'course_title' => $e->course?->title,
                'course_category' => $e->course?->category,
                'thumbnail_url' => $e->course?->thumbnail_url,
                'progress_percentage' => (float) $e->progress_percentage,
                'enrolled_at' => $e->enrolled_at?->toIso8601String(),
                'completed_at' => $e->completed_at?->toIso8601String(),
            ]);

        // ── Score trend (last 10, chronological) ─────────────────
        $trend = QuizAttempt::with('quiz:id,name')
            ->where('user_id', $userId)
            ->whereIn('status', ['completed', 'expired'])
            ->when($since, fn ($q) => $q->where('completed_at', '>=', $since))
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($a) => [
                'quiz_name' => $a->quiz?->name ?? 'Quiz',
                'percentage' => (float) $a->percentage,
                'passed' => (bool) $a->passed,
                'completed_at' => $a->completed_at?->toIso8601String(),
            ]);

        // ── Recent activity feed ─────────────────────────────────
        $recentAttempts = QuizAttempt::with('quiz:id,name')
            ->where('user_id', $userId)
            ->whereIn('status', ['completed', 'expired'])
            ->latest('completed_at')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'type' => 'attempt',
                'title' => $a->quiz?->name ?? 'Quiz',
                'value' => "{$a->percentage}%",
                'passed' => (bool) $a->passed,
                'at' => $a->completed_at?->toIso8601String(),
            ]);

        $recentCerts = Certificate::with('course:id,title')
            ->where('user_id', $userId)
            ->latest('issued_at')
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'type' => 'certificate',
                'title' => $c->course_title_snapshot,
                'value' => $c->cert_number,
                'status' => $c->status,
                'at' => $c->issued_at?->toIso8601String(),
            ]);

        $recentActivity = $recentAttempts
            ->concat($recentCerts)
            ->sortByDesc('at')
            ->take(8)
            ->values();

        return [
            'window_days' => $days,
            'headline' => [
                'enrolled_count' => $enrolledCount,
                'completed_count' => $completedCount,
                'avg_score_percent' => $avgScore,
                'certificates_earned' => $certsEarned,
            ],
            'enrollments' => $enrollments,
            'score_trend' => $trend,
            'recent_activity' => $recentActivity,
        ];
    }

    private function window(Request $request): ?int
    {
        $d = (int) $request->query('days', 0);
        return in_array($d, [7, 30, 90, 365], true) ? $d : null;
    }
}
