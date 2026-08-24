<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SRS Module 4 Reports — Attendance % + Absentee List.
 */
class AttendanceReportController extends Controller
{
    /** GET /api/v1/attendance-sessions/{session:uuid}/report */
    public function session(AttendanceSession $session, Request $request): JsonResponse
    {
        $this->authorizeOwner($session, $request);
        $session->load('records.student.profile:user_id,full_name');

        $enrolled = $session->expectedStudentIds();
        $totalExpected = $enrolled->count();

        $records = $session->records;
        $byStatus = $records->groupBy('status')->map->count();
        $absentees = $records->where('status', 'absent')
            ->merge($this->neverCheckedIn($session, $enrolled))
            ->unique(fn ($r) => $r->student_id ?? $r['student_id'] ?? null);

        return $this->success([
            'session' => [
                'uuid' => $session->uuid,
                'title' => $session->title,
                'starts_at' => $session->starts_at->toIso8601String(),
                'status' => $session->status,
            ],
            'totals' => [
                'expected' => $totalExpected,
                'checked_in' => (int) ($byStatus['present'] ?? 0) + (int) ($byStatus['late'] ?? 0),
                'present' => (int) ($byStatus['present'] ?? 0),
                'late' => (int) ($byStatus['late'] ?? 0),
                'absent' => (int) ($byStatus['absent'] ?? 0),
                'excused' => (int) ($byStatus['excused'] ?? 0),
            ],
            'attendance_percentage' => $totalExpected > 0
                ? round((($byStatus['present'] ?? 0) + ($byStatus['late'] ?? 0)) / $totalExpected * 100, 2)
                : 0,
            'absentees' => $absentees->values()->map(fn ($r) => is_array($r) ? $r : [
                'student' => [
                    'uuid' => $r->student?->uuid,
                    'email' => $r->student?->email,
                    'full_name' => $r->student?->profile?->full_name,
                ],
            ]),
            'records' => $records->map(fn ($r) => [
                'uuid' => $r->uuid,
                'student' => [
                    'uuid' => $r->student?->uuid,
                    'email' => $r->student?->email,
                    'full_name' => $r->student?->profile?->full_name,
                ],
                'status' => $r->status,
                'method' => $r->method,
                'checked_in_at' => $r->checked_in_at?->toIso8601String(),
            ]),
        ]);
    }

    /** GET /api/v1/courses/{course:uuid}/attendance-summary
     *  Aggregate %attendance per enrolled student across all sessions of the course.
     */
    public function courseSummary(Course $course, Request $request): JsonResponse
    {
        $u = $request->user();
        if ($course->instructor_id !== $u->id && !$u->hasRole('system_admin')) {
            abort(403, 'Not your course.');
        }

        $sessionIds = AttendanceSession::where('course_id', $course->id)->pluck('id');
        $totalSessions = $sessionIds->count();

        $enrollments = Enrollment::with('user.profile:user_id,full_name')
            ->where('course_id', $course->id)
            ->get();

        $rows = $enrollments->map(function ($e) use ($sessionIds, $totalSessions) {
            $counts = DB::table('attendance_records')
                ->whereIn('attendance_session_id', $sessionIds)
                ->where('student_id', $e->user_id)
                ->select('status', DB::raw('COUNT(*) as c'))
                ->groupBy('status')
                ->pluck('c', 'status');

            $present = (int) ($counts['present'] ?? 0);
            $late = (int) ($counts['late'] ?? 0);
            $absent = (int) ($counts['absent'] ?? 0);
            $excused = (int) ($counts['excused'] ?? 0);

            return [
                'student' => [
                    'uuid' => $e->user?->uuid,
                    'email' => $e->user?->email,
                    'full_name' => $e->user?->profile?->full_name,
                ],
                'sessions_total' => $totalSessions,
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'excused' => $excused,
                'attendance_percentage' => $totalSessions > 0
                    ? round(($present + $late) / $totalSessions * 100, 2)
                    : 0,
            ];
        });

        return $this->success([
            'course' => ['uuid' => $course->uuid, 'title' => $course->title],
            'sessions_total' => $totalSessions,
            'rows' => $rows,
        ]);
    }

    /** Non-recorded enrolled students (for open sessions). */
    private function neverCheckedIn(AttendanceSession $session, $enrolledIds): \Illuminate\Support\Collection
    {
        if ($session->status !== 'open') return collect();
        $recorded = $session->records->pluck('student_id');
        $missing = collect($enrolledIds)->diff($recorded);
        return \App\Models\User::whereIn('id', $missing)->with('profile:user_id,full_name')->get()->map(fn ($u) => [
            'student' => [
                'uuid' => $u->uuid,
                'email' => $u->email,
                'full_name' => $u->profile?->full_name,
            ],
        ]);
    }

    private function authorizeOwner(AttendanceSession $session, Request $request): void
    {
        $u = $request->user();
        if ($session->trainer_id !== $u->id && !$u->hasRole('system_admin')) {
            abort(403, 'Not your session.');
        }
    }
}
