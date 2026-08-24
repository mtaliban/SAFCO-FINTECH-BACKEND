<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * SRS Module 11 — Corporate Client Dashboard.
 *
 *   headline: Employees Total · Trained · Completion % · Avg Score · Certs
 *   by_department: per-employee aggregates rolled up per department (bar)
 *   status_distribution: not_started / in_progress / completed (donut)
 *   top_performers: top 5 employees by per-employee avg score
 *   courses_progress: per-course rollup (enrolled, completed, in_progress, avg progress)
 *
 * Notes:
 *  - by_department uses PER-EMPLOYEE aggregates first to avoid the cartesian bias
 *    that would let a heavy user distort the departmental average.
 *  - status_distribution uses parameterized IN binding (no inline implode).
 *  - Cached per-user for 60s so refetchInterval=30s doesn't hit DB every time.
 */
class CorporateDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = $user->organization_id;
        if (!$orgId) {
            return $this->error('Your account is not linked to an organization.', 422);
        }

        $days = $this->window($request);
        $cacheKey = "dash:corp:{$user->id}:{$orgId}:d{$days}";

        $payload = Cache::remember($cacheKey, 60, function () use ($orgId, $days) {
            return $this->build($orgId, $days);
        });

        return $this->success($payload);
    }

    private function build(int $orgId, ?int $days): array
    {
        $since = $days ? Carbon::now()->subDays($days) : null;

        // Employees = users in same organization with student role
        $employeeIds = User::where('organization_id', $orgId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'student'))
            ->pluck('id')
            ->all();

        $employeesTotal = count($employeeIds);

        if ($employeesTotal === 0) {
            return $this->emptyPayload();
        }

        // ── Per-employee aggregate view (subquery) ─────────────────
        //   For each employee: total_enrollments, completed_count,
        //   avg_progress, attempts_count, avg_score. This is the
        //   ONE place we compute per-user; every downstream stat
        //   is a weighted rollup off this view — no cartesian bias.
        $enrollBase = DB::table('enrollments')
            ->select('user_id')
            ->whereIn('user_id', $employeeIds)
            ->selectRaw('COUNT(*) as total_enrollments')
            ->selectRaw('SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed_count')
            ->selectRaw('AVG(progress_percentage) as avg_progress');
        if ($since) {
            $enrollBase->where('enrolled_at', '>=', $since);
        }
        $enrollBase->groupBy('user_id');

        $attemptBase = DB::table('quiz_attempts')
            ->select('user_id')
            ->whereIn('user_id', $employeeIds)
            ->whereIn('status', ['completed', 'expired'])
            ->selectRaw('COUNT(*) as attempts_count')
            ->selectRaw('AVG(percentage) as avg_score');
        if ($since) {
            $attemptBase->where('completed_at', '>=', $since);
        }
        $attemptBase->groupBy('user_id');

        $perEmployee = DB::table('users')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->leftJoinSub($enrollBase, 'e', 'e.user_id', '=', 'users.id')
            ->leftJoinSub($attemptBase, 'a', 'a.user_id', '=', 'users.id')
            ->whereIn('users.id', $employeeIds)
            ->select(
                'users.id',
                'users.uuid',
                'users.email',
                DB::raw("TRIM(CONCAT(COALESCE(user_profiles.first_name, ''), ' ', COALESCE(user_profiles.last_name, ''))) as name"),
                DB::raw("COALESCE(NULLIF(user_profiles.department, ''), 'Unassigned') as department"),
                DB::raw('COALESCE(e.total_enrollments, 0) as total_enrollments'),
                DB::raw('COALESCE(e.completed_count, 0) as completed_count'),
                'e.avg_progress',
                DB::raw('COALESCE(a.attempts_count, 0) as attempts_count'),
                'a.avg_score',
            )
            ->get();

        // ── Headline (unbiased) ───────────────────────────────────
        $employeesTrained = $perEmployee->where('completed_count', '>', 0)->count();
        $totalEnrollments = (int) $perEmployee->sum('total_enrollments');
        $totalCompleted   = (int) $perEmployee->sum('completed_count');
        $completionPct    = $totalEnrollments > 0
            ? round(($totalCompleted / $totalEnrollments) * 100, 1)
            : 0.0;

        // "Avg Score" per SRS = average of the per-employee averages
        // (each employee weighted equally, not per-attempt).
        $scoredEmployees = $perEmployee->whereNotNull('avg_score');
        $avgScore = $scoredEmployees->count() > 0
            ? round($scoredEmployees->avg('avg_score'), 1)
            : 0.0;

        $certQuery = Certificate::whereIn('user_id', $employeeIds)
            ->where('status', Certificate::STATUS_ACTIVE);
        if ($since) $certQuery->where('issued_at', '>=', $since);
        $certsIssued = $certQuery->count();

        // ── by_department (from perEmployee) ──────────────────────
        $byDept = $perEmployee
            ->groupBy('department')
            ->map(function ($group, $dept) {
                $withProgress = $group->whereNotNull('avg_progress');
                $withScore    = $group->whereNotNull('avg_score');
                return [
                    'department' => $dept,
                    'employees' => $group->count(),
                    'enrollments' => (int) $group->sum('total_enrollments'),
                    'completed' => (int) $group->sum('completed_count'),
                    'avg_progress' => $withProgress->count() > 0
                        ? round($withProgress->avg('avg_progress'), 1)
                        : 0.0,
                    'avg_score' => $withScore->count() > 0
                        ? round($withScore->avg('avg_score'), 1)
                        : null,
                ];
            })
            ->sortByDesc('employees')
            ->values();

        // ── status_distribution (from perEmployee) ────────────────
        $notStarted = $perEmployee->where('total_enrollments', 0)->count();
        $completed  = $perEmployee->filter(fn ($r) => $r->total_enrollments > 0 && $r->completed_count === (int) $r->total_enrollments)->count();
        $inProgress = $perEmployee->count() - $notStarted - $completed;

        $statusDistribution = [
            ['status' => 'not_started', 'count' => $notStarted],
            ['status' => 'in_progress', 'count' => $inProgress],
            ['status' => 'completed',   'count' => $completed],
        ];

        // ── Top performers (per-employee avg, weighted by attempts) ─
        $topPerformers = $perEmployee
            ->filter(fn ($r) => $r->attempts_count > 0 && $r->avg_score !== null)
            ->sortByDesc('avg_score')
            ->take(5)
            ->map(fn ($r) => [
                'id' => $r->uuid,
                'name' => $r->name ?: $r->email,
                'department' => $r->department === 'Unassigned' ? null : $r->department,
                'attempts' => (int) $r->attempts_count,
                'avg_score' => round((float) $r->avg_score, 1),
            ])
            ->values();

        // ── Per-course rollup ────────────────────────────────────
        $courseQuery = DB::table('enrollments')
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->whereIn('enrollments.user_id', $employeeIds)
            ->whereNull('courses.deleted_at')
            ->select(
                'courses.uuid as id',
                'courses.title',
                'courses.category',
                DB::raw('COUNT(enrollments.id) as enrolled'),
                DB::raw('SUM(CASE WHEN enrollments.completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed'),
                DB::raw('ROUND(AVG(enrollments.progress_percentage), 1) as avg_progress'),
            )
            ->groupBy('courses.uuid', 'courses.title', 'courses.category')
            ->orderByDesc('enrolled')
            ->limit(20);
        if ($since) $courseQuery->where('enrollments.enrolled_at', '>=', $since);
        $courses = $courseQuery->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'category' => $r->category,
                'enrolled' => (int) $r->enrolled,
                'completed' => (int) $r->completed,
                'in_progress' => (int) $r->enrolled - (int) $r->completed,
                'avg_progress' => (float) $r->avg_progress,
            ]);

        return [
            'window_days' => $days,
            'headline' => [
                'employees_total' => $employeesTotal,
                'employees_trained' => $employeesTrained,
                'completion_percent' => $completionPct,
                'avg_score_percent' => $avgScore,
                'certificates_earned' => $certsIssued,
            ],
            'by_department' => $byDept,
            'status_distribution' => $statusDistribution,
            'top_performers' => $topPerformers,
            'courses_progress' => $courses,
        ];
    }

    private function emptyPayload(): array
    {
        return [
            'window_days' => null,
            'headline' => [
                'employees_total' => 0,
                'employees_trained' => 0,
                'completion_percent' => 0.0,
                'avg_score_percent' => 0.0,
                'certificates_earned' => 0,
            ],
            'by_department' => [],
            'status_distribution' => [
                ['status' => 'not_started', 'count' => 0],
                ['status' => 'in_progress', 'count' => 0],
                ['status' => 'completed', 'count' => 0],
            ],
            'top_performers' => [],
            'courses_progress' => [],
        ];
    }

    /** Accept ?days=30|90|365; anything else => all-time. */
    private function window(Request $request): ?int
    {
        $d = (int) $request->query('days', 0);
        return in_array($d, [7, 30, 90, 365], true) ? $d : null;
    }

    /**
     * SRS Progress Reports drill-down: per employee, course-by-course.
     * GET /corporate/employees/{employee_uuid}/report
     */
    public function employeeReport(Request $request, string $employeeUuid): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        if (!$orgId) return $this->error('Not linked to organization.', 422);

        $emp = User::where('uuid', $employeeUuid)
            ->where('organization_id', $orgId)
            ->first();
        if (!$emp) return $this->error('Employee not found in your organization.', 404);

        $enrollments = Enrollment::with(['course:id,uuid,title,category'])
            ->where('user_id', $emp->id)
            ->latest('enrolled_at')
            ->get()
            ->map(fn ($e) => [
                'course_id' => $e->course?->uuid,
                'course_title' => $e->course?->title,
                'category' => $e->course?->category,
                'progress' => (float) $e->progress_percentage,
                'enrolled_at' => $e->enrolled_at?->toIso8601String(),
                'completed_at' => $e->completed_at?->toIso8601String(),
            ]);

        $attempts = QuizAttempt::with(['quiz:id,name,mode'])
            ->where('user_id', $emp->id)
            ->whereIn('status', ['completed', 'expired'])
            ->latest('completed_at')
            ->limit(50)
            ->get()
            ->map(fn ($a) => [
                'quiz' => $a->quiz?->name,
                'mode' => $a->quiz?->mode,
                'percentage' => (float) $a->percentage,
                'passed' => (bool) $a->passed,
                'completed_at' => $a->completed_at?->toIso8601String(),
            ]);

        $certs = Certificate::where('user_id', $emp->id)
            ->latest('issued_at')
            ->get(['cert_number', 'course_title_snapshot', 'status', 'issued_at', 'revoked_at']);

        return $this->success([
            'employee' => [
                'id' => $emp->uuid,
                'email' => $emp->email,
                'name' => trim(($emp->profile->first_name ?? '') . ' ' . ($emp->profile->last_name ?? '')) ?: $emp->email,
                'department' => $emp->profile->department ?? null,
            ],
            'enrollments' => $enrollments,
            'attempts' => $attempts,
            'certificates' => $certs,
        ]);
    }
}
