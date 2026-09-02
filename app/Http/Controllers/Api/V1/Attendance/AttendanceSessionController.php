<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\User;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SRS Module 4 — trainer manages attendance sessions.
 *
 * Lifecycle: scheduled → open (trainer clicks Start; QR becomes active)
 *          → closed  (trainer clicks Close; absentees are computed from enrollment)
 */
class AttendanceSessionController extends Controller
{
    /** GET /api/v1/attendance-sessions — trainer sees own, admin sees all */
    public function index(Request $request): JsonResponse
    {
        $q = AttendanceSession::with(['course:id,uuid,title', 'trainer:id,uuid,email'])
            ->withCount('records');

        $user = $request->user();
        if (!$user->hasRole('system_admin')) {
            $q->where('trainer_id', $user->id);
        }

        $page = $q->latest('starts_at')->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $page->getCollection()->map(fn ($s) => $this->transform($s)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** POST /api/v1/attendance-sessions */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'course_uuid' => ['nullable', 'exists:courses,uuid'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'late_threshold_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'status' => ['nullable', 'in:scheduled,open'],
        ]);

        $courseId = null;
        if (!empty($data['course_uuid'])) {
            $courseId = \App\Models\Course::where('uuid', $data['course_uuid'])->value('id');
        }

        $session = AttendanceSession::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'course_id' => $courseId,
            'trainer_id' => $request->user()->id,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'late_threshold_minutes' => $data['late_threshold_minutes'] ?? 10,
            'status' => $data['status'] ?? 'scheduled',
            'opened_at' => ($data['status'] ?? '') === 'open' ? now() : null,
        ]);

        return $this->success($this->transform($session->fresh(['course', 'trainer'])), 'Session created', 201);
    }

    /** GET /api/v1/attendance-sessions/{session:uuid} */
    public function show(AttendanceSession $session, Request $request): JsonResponse
    {
        $this->authorizeOwner($session, $request);
        $session->load(['course:id,uuid,title', 'trainer:id,uuid,email', 'records.student:id,uuid,email']);

        return $this->success(array_merge($this->transform($session), [
            'records' => $session->records->map(fn ($r) => [
                'uuid' => $r->uuid,
                'student' => ['uuid' => $r->student?->uuid, 'email' => $r->student?->email],
                'status' => $r->status,
                'method' => $r->method,
                'checked_in_at' => $r->checked_in_at?->toIso8601String(),
                'notes' => $r->notes,
            ]),
            'expected_students' => $this->expectedStudents($session),
        ]));
    }

    /** POST /api/v1/attendance-sessions/{session:uuid}/open */
    public function open(AttendanceSession $session, Request $request): JsonResponse
    {
        $this->authorizeOwner($session, $request);
        if ($session->status === 'closed') return $this->error('Session already closed.', 422);
        $session->update(['status' => 'open', 'opened_at' => $session->opened_at ?? now()]);
        return $this->success($this->transform($session->fresh()), 'Session opened');
    }

    /** POST /api/v1/attendance-sessions/{session:uuid}/close
     *  Auto-marks non-checked-in enrolled students as 'absent'.
     */
    public function close(AttendanceSession $session, Request $request): JsonResponse
    {
        $this->authorizeOwner($session, $request);

        $enrolled = $session->expectedStudentIds();
        $alreadyRecorded = $session->records()->pluck('student_id');
        $absent = $enrolled->diff($alreadyRecorded);

        foreach ($absent as $sid) {
            $session->records()->create([
                'student_id' => $sid,
                'status' => 'absent',
                'method' => 'auto',
                'marked_by' => $request->user()->id,
            ]);
        }
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        return $this->success($this->transform($session->fresh()), 'Session closed. ' . $absent->count() . ' students auto-marked absent.');
    }

    /** POST /api/v1/attendance-sessions/{session:uuid}/rotate-qr */
    public function rotateQr(AttendanceSession $session, Request $request): JsonResponse
    {
        $this->authorizeOwner($session, $request);
        $session->rotateQr((int) ($request->query('expires_in') ?: 0) ?: null);
        return $this->success([
            'qr_token' => $session->qr_token,
            'qr_expires_at' => $session->qr_expires_at?->toIso8601String(),
        ], 'QR rotated');
    }

    /** GET /api/v1/attendance-sessions/{session:uuid}/qr — returns SVG image */
    public function qr(AttendanceSession $session, Request $request): Response
    {
        $this->authorizeOwner($session, $request);

        $frontendBase = env('FRONTEND_URL', 'http://localhost:3002');
        $checkInUrl = rtrim($frontendBase, '/').'/student/check-in?token='.$session->qr_token;

        $renderer = new ImageRenderer(new RendererStyle(360), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($checkInUrl);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /** GET /api/v1/student/live-sessions — live + upcoming sessions for enrolled courses */
    public function studentSessions(Request $request): JsonResponse
    {
        $student = $request->user();
        $enrolledCourseIds = \App\Models\Enrollment::where('user_id', $student->id)->pluck('course_id');

        $sessions = AttendanceSession::where(function ($q) use ($enrolledCourseIds) {
            // Standalone (no course) OR enrolled in the course
            $q->whereNull('course_id')->orWhereIn('course_id', $enrolledCourseIds);
        })
        ->where(function ($q) {
            $q->where('status', 'open')
              ->orWhere(function ($q2) {
                  // Scheduled within next 48h
                  $q2->where('status', 'scheduled')->where('starts_at', '<=', now()->addHours(48));
              });
        })
        ->with('course:id,uuid,title', 'trainer:id,uuid,email')
        ->orderByRaw("FIELD(status,'open','scheduled')")
        ->orderBy('starts_at')
        ->get();

        return $this->success($sessions->map(fn ($s) => [
            'uuid' => $s->uuid,
            'title' => $s->title,
            'location' => $s->location,
            'status' => $s->status,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'course' => $s->course ? ['uuid' => $s->course->uuid, 'title' => $s->course->title] : null,
            'jitsi_room' => 'safco-lms-' . $s->uuid,
        ]));
    }

    /** GET /api/v1/attendance-sessions/{session:uuid}/peek  (student sees basic session info) */
    public function peek(AttendanceSession $session): JsonResponse
    {
        $session->load('course:id,uuid,title');
        return $this->success([
            'uuid' => $session->uuid,
            'title' => $session->title,
            'location' => $session->location,
            'status' => $session->status,
            'starts_at' => $session->starts_at?->toIso8601String(),
            'course' => $session->course ? ['uuid' => $session->course->uuid, 'title' => $session->course->title] : null,
            'jitsi_room' => 'safco-lms-' . $session->uuid,
        ]);
    }

    /** DELETE /api/v1/attendance-sessions/{session:uuid} */
    public function destroy(AttendanceSession $session, Request $request): JsonResponse
    {
        $this->authorizeOwner($session, $request);
        $session->delete();
        return $this->success(null, 'Session deleted');
    }

    private function authorizeOwner(AttendanceSession $session, Request $request): void
    {
        $u = $request->user();
        if ($session->trainer_id !== $u->id && !$u->hasRole('system_admin')) {
            abort(403, 'Not your attendance session.');
        }
    }

    private function transform(AttendanceSession $s): array
    {
        return [
            'uuid' => $s->uuid,
            'title' => $s->title,
            'description' => $s->description,
            'location' => $s->location,
            'course' => $s->course ? ['uuid' => $s->course->uuid, 'title' => $s->course->title] : null,
            'trainer' => $s->trainer ? ['uuid' => $s->trainer->uuid, 'email' => $s->trainer->email] : null,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'ends_at' => $s->ends_at?->toIso8601String(),
            'late_threshold_minutes' => (int) $s->late_threshold_minutes,
            'status' => $s->status,
            'opened_at' => $s->opened_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'qr_token' => $s->qr_token,
            'qr_expires_at' => $s->qr_expires_at?->toIso8601String(),
            'records_count' => $s->records_count ?? $s->records->count() ?? 0,
            'jitsi_room' => 'safco-lms-' . $s->uuid,
        ];
    }

    private function expectedStudents(AttendanceSession $s): array
    {
        if (!$s->course_id) return [];
        return \App\Models\User::whereIn('id', $s->expectedStudentIds())
            ->with('profile:user_id,full_name')
            ->get(['id', 'uuid', 'email'])
            ->map(fn ($u) => [
                'uuid' => $u->uuid,
                'email' => $u->email,
                'full_name' => $u->profile?->full_name,
            ])->toArray();
    }
}
