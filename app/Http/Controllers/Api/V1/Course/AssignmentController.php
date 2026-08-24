<?php

namespace App\Http\Controllers\Api\V1\Course;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Enrollment;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SRS 4.2 + Module 9 — Assignments.
 *
 *   Trainer  → creates + uploads brief (PDF/Word/Excel/ZIP) + grades submissions
 *   Student  → downloads brief (signed short-lived URL) + uploads answer
 *   Files    → stored on PRIVATE `local` disk (storage/app/private/assignments/...)
 *              never directly reachable via /storage/. Downloads served through
 *              authenticated + signed endpoints so one student cannot view another's submission.
 */
class AssignmentController extends Controller
{
    /** URL::temporarySignedRoute lifetime for brief + submission downloads. */
    private const SIGNED_URL_MINUTES = 30;

    /** Which disk we store files on. Private = not reachable via /storage/*. */
    private const DISK = 'local';

    /* ------------------------------------------------------------ *
     * Assignment CRUD (trainer)
     * ------------------------------------------------------------ */

    public function store(Lesson $lesson, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($lesson, $request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'max_points' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'due_date' => ['nullable', 'date'],
            'allowed_file_types' => ['nullable', 'array'],
            'allowed_file_types.*' => ['string', Rule::in(Assignment::ALLOWED_EXTENSIONS)],
        ]);

        if (empty($data['allowed_file_types'])) {
            $data['allowed_file_types'] = Assignment::ALLOWED_EXTENSIONS;
        }

        $a = $lesson->assignments()->create($data + ['max_points' => $data['max_points'] ?? 100]);
        return $this->success($this->transform($a), 'Assignment created', 201);
    }

    public function update(Assignment $assignment, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($assignment->lesson, $request);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'max_points' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'allowed_file_types' => ['sometimes', 'nullable', 'array'],
            'allowed_file_types.*' => ['string', Rule::in(Assignment::ALLOWED_EXTENSIONS)],
        ]);
        $assignment->update($data);
        return $this->success($this->transform($assignment->fresh()), 'Assignment updated');
    }

    /** DELETE — model's `deleting` hook (in Assignment.php) purges brief + all submission files. */
    public function destroy(Assignment $assignment, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($assignment->lesson, $request);
        $assignment->delete();
        return $this->success(null, 'Assignment deleted');
    }

    /* ------------------------------------------------------------ *
     * Brief file (trainer)
     * ------------------------------------------------------------ */

    public function uploadBrief(Assignment $assignment, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($assignment->lesson, $request);
        $request->validate([
            'file' => [
                'required', 'file',
                'max:' . Assignment::MAX_UPLOAD_KB,
                'mimes:' . implode(',', Assignment::ALLOWED_EXTENSIONS),
            ],
        ]);

        if ($assignment->brief_file_url) {
            Storage::disk(self::DISK)->delete($assignment->brief_file_url);
        }

        $file = $request->file('file');
        $path = $file->store("assignments/{$assignment->uuid}/brief", self::DISK);
        $assignment->update([
            'brief_file_url' => $path,      // relative path on private disk (NOT a public URL)
            'brief_file_name' => $file->getClientOriginalName(),
            'brief_file_size' => $file->getSize(),
            'brief_mime_type' => $file->getMimeType(),
        ]);
        return $this->success($this->transform($assignment->fresh()), 'Brief uploaded');
    }

    public function deleteBrief(Assignment $assignment, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($assignment->lesson, $request);
        if ($assignment->brief_file_url) {
            Storage::disk(self::DISK)->delete($assignment->brief_file_url);
        }
        $assignment->update([
            'brief_file_url' => null, 'brief_file_name' => null,
            'brief_file_size' => null, 'brief_mime_type' => null,
        ]);
        return $this->success($this->transform($assignment->fresh()), 'Brief removed');
    }

    /**
     * GET /api/v1/download/assignment-brief/{assignment:uuid}
     *
     * Streams the private brief file. Two auth layers:
     *   1. `signed` middleware verifies the temporary signed URL hasn't been tampered with or expired
     *   2. Requesting user must be the course instructor / admin / an enrolled student
     */
    public function downloadBrief(Assignment $assignment, Request $request): StreamedResponse
    {
        $this->authorizeAssignmentAccess($assignment, $request);

        if (! $assignment->brief_file_url || ! Storage::disk(self::DISK)->exists($assignment->brief_file_url)) {
            abort(404, 'Brief not available.');
        }

        return Storage::disk(self::DISK)->download(
            $assignment->brief_file_url,
            $assignment->brief_file_name ?? basename($assignment->brief_file_url),
            ['Content-Type' => $assignment->brief_mime_type ?: 'application/octet-stream'],
        );
    }

    /* ------------------------------------------------------------ *
     * Assignment view (student sees own submission; trainer sees counts)
     * ------------------------------------------------------------ */

    public function show(Assignment $assignment, Request $request): JsonResponse
    {
        $user = $request->user();
        $isOwner = $assignment->lesson->module->course->instructor_id === $user->id || $user->hasRole('system_admin');
        $mine = null;

        if ($user->hasRole('student')) {
            $enrolled = Enrollment::where('user_id', $user->id)
                ->where('course_id', $assignment->lesson->module->course_id)
                ->exists();
            if (!$enrolled && !$isOwner) abort(403, 'Enroll in the course first.');
            $mine = AssignmentSubmission::where('assignment_id', $assignment->id)
                ->where('student_id', $user->id)
                ->first();
        } elseif (!$isOwner) {
            abort(403, 'Not your assignment.');
        }

        return $this->success([
            'assignment' => $this->transform($assignment),
            'my_submission' => $mine ? $this->transformSubmission($mine) : null,
            'submission_count' => $isOwner ? $assignment->submissions()->count() : null,
        ]);
    }

    /* ------------------------------------------------------------ *
     * Submissions (student)
     * ------------------------------------------------------------ */

    public function submit(Assignment $assignment, Request $request): JsonResponse
    {
        $user = $request->user();
        $courseId = $assignment->lesson->module->course_id;

        $enrolled = Enrollment::where('user_id', $user->id)->where('course_id', $courseId)->exists();
        if (!$enrolled) return $this->error('Enroll in the course first.', 422);

        $allowed = $assignment->allowed_file_types ?: Assignment::ALLOWED_EXTENSIONS;

        $data = $request->validate([
            'answer_text' => ['nullable', 'string', 'max:10000'],
            'file' => [
                'nullable', 'file',
                'max:' . Assignment::MAX_UPLOAD_KB,
                'mimes:' . implode(',', $allowed),
            ],
        ], [
            'file.mimes' => 'Only these file types are allowed: ' . implode(', ', $allowed) . '.',
            'file.max' => 'File exceeds ' . (Assignment::MAX_UPLOAD_KB / 1024) . ' MB limit.',
        ]);

        if (empty($data['answer_text']) && !$request->hasFile('file')) {
            return $this->error('Provide answer_text or a file.', 422);
        }

        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('student_id', $user->id)
            ->first();
        if ($existing && $existing->file_url && $request->hasFile('file')) {
            Storage::disk(self::DISK)->delete($existing->file_url);
        }

        $fileUrl = $existing?->file_url;
        $fileName = $existing?->file_name;
        $fileSize = $existing?->file_size;
        $mime = $existing?->mime_type;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store("assignments/{$assignment->uuid}/submissions", self::DISK);
            $fileUrl = $path;
            $fileName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $mime = $file->getMimeType();
        }

        $submission = AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'student_id' => $user->id],
            [
                'answer_text' => $data['answer_text'] ?? null,
                'file_url' => $fileUrl,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'mime_type' => $mime,
                'submitted_at' => now(),
                'status' => 'submitted',
                'grade' => null,
                'feedback' => null,
                'graded_by' => null,
                'graded_at' => null,
            ]
        );

        return $this->success($this->transformSubmission($submission->fresh()), 'Submission received', 201);
    }

    public function submissions(Assignment $assignment, Request $request): JsonResponse
    {
        $user = $request->user();
        $q = AssignmentSubmission::with(['student.profile:id,user_id,first_name,last_name'])
            ->where('assignment_id', $assignment->id);

        if ($user->hasRole('student')) {
            $q->where('student_id', $user->id);
        } elseif (!$user->hasRole('system_admin') && $assignment->lesson->module->course->instructor_id !== $user->id) {
            abort(403, 'Not your assignment.');
        }

        return $this->success([
            'data' => $q->latest('submitted_at')->get()->map(fn ($s) => $this->transformSubmission($s)),
        ]);
    }

    /**
     * GET /api/v1/download/submission/{submission:uuid}
     *
     * Streams the submission answer file. Signed URL + policy:
     *   - the submission owner (student)
     *   - the course instructor
     *   - admin
     * … otherwise 403.
     */
    public function downloadSubmission(AssignmentSubmission $submission, Request $request): StreamedResponse
    {
        $user = $request->user();
        $ownerId = $submission->assignment->lesson->module->course->instructor_id;
        $isSelf = $submission->student_id === $user->id;
        $isOwner = $ownerId === $user->id;
        $isAdmin = $user->hasRole('system_admin');
        if (!$isSelf && !$isOwner && !$isAdmin) abort(403, 'Not your submission.');

        if (!$submission->file_url || !Storage::disk(self::DISK)->exists($submission->file_url)) {
            abort(404, 'Submission file not available.');
        }

        return Storage::disk(self::DISK)->download(
            $submission->file_url,
            $submission->file_name ?? basename($submission->file_url),
            ['Content-Type' => $submission->mime_type ?: 'application/octet-stream'],
        );
    }

    public function grade(AssignmentSubmission $submission, Request $request): JsonResponse
    {
        $this->authorizeCourseOwner($submission->assignment->lesson, $request);
        $data = $request->validate([
            'grade' => ['required', 'integer', 'min:0', 'max:' . $submission->assignment->max_points],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);
        $submission->update([
            'grade' => $data['grade'],
            'feedback' => $data['feedback'] ?? null,
            'status' => 'graded',
            'graded_by' => $request->user()->id,
            'graded_at' => now(),
        ]);
        return $this->success($this->transformSubmission($submission->fresh()), 'Graded');
    }

    /** GET /api/v1/student/assignments */
    public function studentIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $courseIds = Enrollment::where('user_id', $user->id)->pluck('course_id');
        if ($courseIds->isEmpty()) return $this->success([]);

        $assignments = Assignment::with(['lesson.module.course:id,uuid,title'])
            ->whereHas('lesson.module', fn ($q) => $q->whereIn('course_id', $courseIds))
            ->orderBy('due_date')
            ->get();

        $mySubs = AssignmentSubmission::where('student_id', $user->id)
            ->whereIn('assignment_id', $assignments->pluck('id'))
            ->get()
            ->keyBy('assignment_id');

        $data = $assignments->map(function (Assignment $a) use ($mySubs) {
            $sub = $mySubs[$a->id] ?? null;
            $overdue = $a->due_date && $a->due_date->isPast() && !$sub;
            $row = $this->transform($a);
            $row['course'] = $a->lesson?->module?->course
                ? ['id' => $a->lesson->module->course->uuid, 'title' => $a->lesson->module->course->title]
                : null;
            $row['lesson'] = $a->lesson ? ['id' => $a->lesson->uuid, 'title' => $a->lesson->title] : null;
            $row['my_status'] = $sub?->status ?? ($overdue ? 'overdue' : 'pending');
            $row['my_grade'] = $sub?->grade;
            $row['my_submitted_at'] = $sub?->submitted_at?->toIso8601String();
            return $row;
        });

        return $this->success($data);
    }

    /* ------------------------------------------------------------ *
     * Access control + transformers
     * ------------------------------------------------------------ */

    private function authorizeCourseOwner(Lesson $lesson, Request $request): void
    {
        $user = $request->user();
        $ownerId = $lesson->module->course->instructor_id;
        if ($ownerId !== $user->id && !$user->hasRole('system_admin')) {
            abort(403, 'Not your course.');
        }
    }

    /** Trainer of the parent course OR admin OR enrolled student may access this assignment's brief. */
    private function authorizeAssignmentAccess(Assignment $assignment, Request $request): void
    {
        $user = $request->user();
        if (!$user) abort(401, 'Login required.');
        $ownerId = $assignment->lesson->module->course->instructor_id;
        if ($user->hasRole('system_admin') || $ownerId === $user->id) return;
        $enrolled = Enrollment::where('user_id', $user->id)
            ->where('course_id', $assignment->lesson->module->course_id)
            ->exists();
        if (!$enrolled) abort(403, 'Not enrolled in this course.');
    }

    /** Build a short-lived signed URL for the private brief download endpoint. */
    public static function briefDownloadUrl(Assignment $a): ?string
    {
        if (!$a->brief_file_url) return null;
        return URL::temporarySignedRoute(
            'assignments.brief.download',
            now()->addMinutes(self::SIGNED_URL_MINUTES),
            ['assignment' => $a->uuid],
        );
    }

    public static function submissionDownloadUrl(AssignmentSubmission $s): ?string
    {
        if (!$s->file_url) return null;
        return URL::temporarySignedRoute(
            'submissions.file.download',
            now()->addMinutes(self::SIGNED_URL_MINUTES),
            ['submission' => $s->uuid],
        );
    }

    private function transform(Assignment $a): array
    {
        return [
            'uuid' => $a->uuid,
            'title' => $a->title,
            'instructions' => $a->instructions,
            'brief' => $a->brief_file_url ? [
                'download_url' => self::briefDownloadUrl($a),
                'file_name' => $a->brief_file_name,
                'file_size' => (int) $a->brief_file_size,
                'mime_type' => $a->brief_mime_type,
                'expires_in_minutes' => self::SIGNED_URL_MINUTES,
            ] : null,
            'max_points' => (int) $a->max_points,
            'due_date' => $a->due_date?->toIso8601String(),
            'allowed_file_types' => $a->allowed_file_types ?: Assignment::ALLOWED_EXTENSIONS,
            'lesson_uuid' => $a->lesson?->uuid,
        ];
    }

    private function transformSubmission(AssignmentSubmission $s): array
    {
        $studentName = $s->student?->profile?->first_name
            ? trim(($s->student->profile->first_name ?? '') . ' ' . ($s->student->profile->last_name ?? ''))
            : ($s->student?->email);

        return [
            'uuid' => $s->uuid,
            'student' => [
                'uuid' => $s->student?->uuid,
                'email' => $s->student?->email,
                'name' => $studentName,
            ],
            'answer_text' => $s->answer_text,
            'file_url' => $s->file_url ? self::submissionDownloadUrl($s) : null,
            'file_name' => $s->file_name,
            'file_size' => (int) $s->file_size,
            'mime_type' => $s->mime_type,
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'status' => $s->status,
            'grade' => $s->grade,
            'feedback' => $s->feedback,
            'graded_at' => $s->graded_at?->toIso8601String(),
        ];
    }
}
