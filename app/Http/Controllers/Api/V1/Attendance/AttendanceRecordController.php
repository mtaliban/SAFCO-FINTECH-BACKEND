<?php

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\EventBus\MqttPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 4 — check-ins (QR by student, manual by trainer).
 * Broadcasts real-time event so trainer dashboard updates without polling.
 */
class AttendanceRecordController extends Controller
{
    public function __construct(protected MqttPublisher $mqtt) {}

    /** POST /api/v1/attendance-sessions/{session:uuid}/mark  (trainer manual) */
    public function mark(AttendanceSession $session, Request $request): JsonResponse
    {
        $this->authorizeOwner($session, $request);
        $data = $request->validate([
            'student_uuid' => ['required', 'exists:users,uuid'],
            'status' => ['required', 'in:present,late,absent,excused'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $student = User::where('uuid', $data['student_uuid'])->firstOrFail();

        $record = AttendanceRecord::updateOrCreate(
            ['attendance_session_id' => $session->id, 'student_id' => $student->id],
            [
                'status' => $data['status'],
                'method' => 'manual',
                'checked_in_at' => in_array($data['status'], ['present', 'late'], true) ? now() : null,
                'notes' => $data['notes'] ?? null,
                'marked_by' => $request->user()->id,
            ]
        );

        $this->broadcastCheckIn($session, $record, $student);
        return $this->success($this->transform($record->fresh('student')), 'Marked', 201);
    }

    /** POST /api/v1/attendance/check-in  (student self-check-in via QR token) */
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $session = AttendanceSession::where('qr_token', $data['token'])->first();
        if (!$session) return $this->error('Invalid QR code.', 404);

        if ($session->qr_expires_at && $session->qr_expires_at->isPast()) {
            return $this->error('QR code has expired. Ask the trainer to rotate.', 410);
        }
        if ($session->status !== 'open') {
            return $this->error('Session is not open for check-in.', 422);
        }

        $now = now();
        $status = $session->classifyCheckIn($now);

        $record = AttendanceRecord::updateOrCreate(
            ['attendance_session_id' => $session->id, 'student_id' => $request->user()->id],
            [
                'status' => $status,
                'method' => 'qr',
                'checked_in_at' => $now,
            ]
        );

        $this->broadcastCheckIn($session, $record, $request->user());

        return $this->success([
            'status' => $record->status,
            'session' => [
                'uuid' => $session->uuid,
                'title' => $session->title,
                'starts_at' => $session->starts_at->toIso8601String(),
                'location' => $session->location,
            ],
            'checked_in_at' => $record->checked_in_at->toIso8601String(),
        ], "Checked in as {$record->status}");
    }

    private function broadcastCheckIn(AttendanceSession $session, AttendanceRecord $record, User $student): void
    {
        $this->mqtt->publishRaw("safco/lms/attendance/{$session->uuid}/checkin", [
            'session_uuid' => $session->uuid,
            'record_uuid' => $record->uuid,
            'student' => [
                'uuid' => $student->uuid,
                'email' => $student->email,
                'full_name' => $student->profile?->full_name,
            ],
            'status' => $record->status,
            'method' => $record->method,
            'checked_in_at' => $record->checked_in_at?->toIso8601String(),
            'ts' => now()->toIso8601String(),
        ]);
    }

    private function authorizeOwner(AttendanceSession $session, Request $request): void
    {
        $u = $request->user();
        if ($session->trainer_id !== $u->id && !$u->hasRole('system_admin')) {
            abort(403, 'Not your attendance session.');
        }
    }

    private function transform(AttendanceRecord $r): array
    {
        return [
            'uuid' => $r->uuid,
            'student' => ['uuid' => $r->student?->uuid, 'email' => $r->student?->email],
            'status' => $r->status,
            'method' => $r->method,
            'checked_in_at' => $r->checked_in_at?->toIso8601String(),
            'notes' => $r->notes,
        ];
    }
}
