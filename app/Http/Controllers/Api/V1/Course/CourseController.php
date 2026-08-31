<?php

namespace App\Http\Controllers\Api\V1\Course;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * SRS 4.2 Module 2 — Course Management.
 *   • Trainer creates courses (SRS 3.2 "Create courses")
 *   • Admin approves before publishing (SRS 3.1 "Approve courses")
 *   • Student browses published + can view details (SRS 3.3)
 */
class CourseController extends Controller
{
    private const CATEGORIES = [
        'microsoft_office', 'excel', 'power_query', 'power_bi',
        'accounting', 'finance', 'ifrs', 'erp_systems',
        'coding', 'data_analytics', 'general',
    ];
    private const LEVELS = ['beginner', 'intermediate', 'advanced', 'expert'];

    /** GET /api/v1/courses
     *  - Student: only published
     *  - Trainer: my courses (+ published visible)
     *  - Admin: all
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Course::query()->with(['instructor:id,uuid,email', 'creator:id,uuid,email'])
            ->withCount('modules', 'enrollments');

        if ($user?->hasRole('student') || $user?->hasRole('corporate_client')) {
            $q->published();
        } elseif ($user?->hasRole('trainer') && !$user->hasRole('system_admin')) {
            $q->where(fn ($w) => $w->where('instructor_id', $user->id)->orWhere('status', 'published'));
        }
        // admin: everything

        if ($cat = $request->query('category')) $q->where('category', $cat);
        if ($status = $request->query('status')) $q->where('status', $status);
        if ($search = $request->query('search')) $q->where('title', 'like', "%{$search}%");

        $page = $q->latest()->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $page->getCollection()->map(fn ($c) => $this->transform($c)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** POST /api/v1/courses (trainer|admin) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'level' => ['nullable', Rule::in(self::LEVELS)],
            'duration_hours' => ['nullable', 'integer', 'min:1'],
            'price_tzs' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'instructor_uuid' => ['nullable', 'exists:users,uuid'],
        ]);

        // Admin/Trainer can pick instructor. Default to self.
        $instructorId = $request->user()->id;
        if (!empty($data['instructor_uuid'])) {
            $picked = \App\Models\User::where('uuid', $data['instructor_uuid'])->first();
            if ($picked && ($request->user()->hasRole('system_admin') || $picked->id === $request->user()->id)) {
                $instructorId = $picked->id;
            }
        }

        $course = Course::create([
            ...\Arr::except($data, ['instructor_uuid']),
            'level' => $data['level'] ?? 'beginner',
            'instructor_id' => $instructorId,
            'created_by' => $request->user()->id,
            'status' => 'draft',
        ]);

        return $this->success($this->transform($course->fresh(['instructor', 'modules'])), 'Course created', 201);
    }

    /** GET /api/v1/courses/{course:uuid} */
    public function show(Course $course, Request $request): JsonResponse
    {
        $user = $request->user();
        $isPublished = $course->status === 'published';
        $isOwner = $user && $course->instructor_id === $user->id;
        $isAdmin = $user && $user->hasRole('system_admin');

        if (!$isPublished && !$isOwner && !$isAdmin) {
            return $this->error('Course not available.', 404);
        }

        $course->load([
            'instructor:id,uuid,email',
            'modules.lessons:id,uuid,course_module_id,title,description,content,duration_seconds,position,video_url,pdf_url',
        ]);

        // Fetch completed lesson UUIDs for the current user in this course
        $completedUuids = [];
        if ($user) {
            $completedUuids = DB::table('lesson_completions')
                ->join('lessons', 'lessons.id', '=', 'lesson_completions.lesson_id')
                ->join('course_modules', 'course_modules.id', '=', 'lessons.course_module_id')
                ->where('lesson_completions.user_id', $user->id)
                ->where('course_modules.course_id', $course->id)
                ->pluck('lessons.uuid')
                ->toArray();
        }

        return $this->success($this->transform($course, includeStructure: true, completedUuids: $completedUuids));
    }

    /** PATCH /api/v1/courses/{course:uuid} (owner or admin) */
    public function update(Course $course, Request $request): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($course, $request);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', Rule::in(self::CATEGORIES)],
            'level' => ['sometimes', Rule::in(self::LEVELS)],
            'duration_hours' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'price_tzs' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'instructor_uuid' => ['sometimes', 'nullable', 'exists:users,uuid'],
            'final_assessment_quiz_uuid' => ['sometimes', 'nullable', 'exists:quizzes,uuid'],
        ]);

        // Reassign instructor (admin only, or self)
        if (array_key_exists('instructor_uuid', $data)) {
            if ($data['instructor_uuid']) {
                $picked = \App\Models\User::where('uuid', $data['instructor_uuid'])->first();
                if ($picked && ($request->user()->hasRole('system_admin') || $picked->id === $request->user()->id)) {
                    $data['instructor_id'] = $picked->id;
                }
            }
            unset($data['instructor_uuid']);
        }

        // Attach final assessment quiz
        if (array_key_exists('final_assessment_quiz_uuid', $data)) {
            if ($data['final_assessment_quiz_uuid']) {
                $quiz = \App\Models\Quiz::where('uuid', $data['final_assessment_quiz_uuid'])->first();
                $data['final_assessment_quiz_id'] = $quiz?->id;
            } else {
                $data['final_assessment_quiz_id'] = null;
            }
            unset($data['final_assessment_quiz_uuid']);
        }

        $course->update($data);
        return $this->success($this->transform($course->fresh()), 'Course updated');
    }

    /** POST /api/v1/courses/{course:uuid}/submit — trainer sends for approval */
    public function submit(Course $course, Request $request): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($course, $request);
        if (!in_array($course->status, ['draft', 'rejected'])) {
            return $this->error("Course is {$course->status}, not draft.", 422);
        }
        if ($course->modules()->count() === 0) {
            return $this->error('Add at least one module before submitting.', 422);
        }
        $course->update(['status' => 'pending_approval']);

        // Notify all system_admins in-app
        try {
            $inApp = app(\App\Services\Notifications\Channels\InAppChannel::class);
            $trainer = $request->user();
            $admins = \App\Models\User::role('system_admin')->get();
            foreach ($admins as $admin) {
                $inApp->send($admin, 'course.submitted_for_approval', [
                    'course_title' => $course->title,
                    'trainer_name' => $trainer->profile?->full_name ?? $trainer->email,
                    'action_url'   => '/admin/course-approvals',
                    'action_label' => 'Kagua Course',
                ]);
            }
        } catch (\Throwable) {}

        return $this->success($this->transform($course->fresh()), 'Submitted for approval');
    }

    /** POST /api/v1/courses/{course:uuid}/thumbnail — image upload */
    public function uploadThumbnail(Course $course, Request $request): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($course, $request);
        $request->validate([
            'thumbnail' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ]);

        $disk = config('filesystems.default', 's3');

        // Delete old file
        $old = $course->thumbnail_url;
        if ($old) {
            try {
                if (str_starts_with($old, 'https://') || str_starts_with($old, 'http://')) {
                    Storage::disk($disk)->delete(ltrim(parse_url($old, PHP_URL_PATH) ?? '', '/'));
                } elseif (str_starts_with($old, '/storage/')) {
                    Storage::disk('public')->delete(str_replace('/storage/', '', $old));
                }
            } catch (\Throwable) {}
        }

        $path = $request->file('thumbnail')->store("courses/{$course->uuid}/thumbnails", $disk);
        $url  = Storage::disk($disk)->url($path);
        $course->update(['thumbnail_url' => $url]);

        return $this->success(['thumbnail_url' => $url], 'Thumbnail uploaded');
    }

    /** DELETE /api/v1/courses/{course:uuid} (owner or admin) */
    public function destroy(Course $course, Request $request): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($course, $request);
        $course->delete();
        return $this->success(null, 'Course archived');
    }

    /** GET /api/v1/instructors — list users with trainer role (for instructor dropdown) */
    public function instructors(Request $request): JsonResponse
    {
        $instructors = \App\Models\User::with('profile:user_id,full_name')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['trainer', 'system_admin']))
            ->get(['id', 'uuid', 'email'])
            ->map(fn ($u) => [
                'uuid' => $u->uuid,
                'email' => $u->email,
                'name' => $u->profile?->full_name ?? $u->email,
            ]);
        return $this->success(['data' => $instructors]);
    }

    // --- Helpers ---

    /**
     * Generate a signed S3 URL for private objects, cached for 23 h so list pages
     * don't hammer the S3 API. Falls back to the raw URL if signing is unsupported.
     */
    private function signedUrl(?string $url): ?string
    {
        if (!$url) return null;
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) return $url;

        $cacheKey = 'signed_url_' . md5($url);
        return Cache::remember($cacheKey, now()->addHours(23), function () use ($url) {
            try {
                $disk = config('filesystems.default', 's3');
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
                return Storage::disk($disk)->temporaryUrl($path, now()->addDay());
            } catch (\Throwable) {
                return $url;
            }
        });
    }

    private function authorizeOwnerOrAdmin(Course $course, Request $request): void
    {
        $user = $request->user();
        if ($course->instructor_id !== $user->id && !$user->hasRole('system_admin')) {
            abort(403, 'Not your course.');
        }
    }

    private function transform(Course $c, bool $includeStructure = false, array $completedUuids = []): array
    {
        $base = [
            'uuid' => $c->uuid,
            'slug' => $c->slug,
            'title' => $c->title,
            'description' => $c->description,
            'category' => $c->category,
            'level' => $c->level,
            'duration_hours' => $c->duration_hours,
            'price_tzs' => $c->price_tzs,
            'is_free' => $c->isFree(),
            'thumbnail_url' => $this->signedUrl($c->thumbnail_url),
            'status' => $c->status,
            'rejection_reason' => $c->rejection_reason,
            'instructor' => $c->instructor ? [
                'uuid' => $c->instructor->uuid,
                'email' => $c->instructor->email,
                'name' => $c->instructor->profile?->full_name,
            ] : null,
            'stats' => [
                'modules' => (int) ($c->modules_count ?? $c->modules->count()),
                'enrollments' => (int) ($c->enrollments_count ?? 0),
            ],
            'published_at' => $c->published_at?->toIso8601String(),
            'approved_at' => $c->approved_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];

        if ($includeStructure && $c->relationLoaded('modules')) {
            $c->load([
                'modules.quizzes:id,uuid,course_module_id,name,status,mode,number_of_questions',
                'modules.lessons.assignments:id,uuid,lesson_id,title,instructions,max_points,due_date,allowed_file_types,brief_file_url,brief_file_name,brief_file_size,brief_mime_type',
                'modules.lessons.materials:id,uuid,lesson_id,type,title,description,url,mime_type,file_size,position,metadata',
                'finalAssessment:id,uuid,name,status',
            ]);
            $base['modules'] = $c->modules->map(fn ($m) => [
                'uuid' => $m->uuid,
                'title' => $m->title,
                'description' => $m->description,
                'position' => $m->position,
                'quizzes' => $m->quizzes->map(fn ($q) => [
                    'uuid' => $q->uuid, 'name' => $q->name, 'status' => $q->status,
                    'mode' => $q->mode, 'number_of_questions' => $q->number_of_questions,
                ]),
                'lessons' => $m->lessons->map(fn ($l) => [
                    'uuid' => $l->uuid,
                    'title' => $l->title,
                    'description' => $l->description,
                    'position' => $l->position,
                    'duration_seconds' => $l->duration_seconds,
                    'content' => $l->content,
                    'video_url' => $l->video_url,
                    'pdf_url' => $l->pdf_url,
                    'is_completed' => in_array($l->uuid, $completedUuids),
                    'assignments' => $l->assignments->map(fn ($a) => [
                        'uuid' => $a->uuid,
                        'title' => $a->title,
                        'instructions' => $a->instructions,
                        'max_points' => (int) $a->max_points,
                        'due_date' => $a->due_date?->toIso8601String(),
                        'allowed_file_types' => $a->allowed_file_types
                            ?: \App\Models\Assignment::ALLOWED_EXTENSIONS,
                        'brief' => $a->brief_file_url ? [
                            'download_url' => \App\Http\Controllers\Api\V1\Course\AssignmentController::briefDownloadUrl($a),
                            'file_name' => $a->brief_file_name,
                            'file_size' => (int) $a->brief_file_size,
                            'mime_type' => $a->brief_mime_type,
                        ] : null,
                    ]),
                    'materials' => $l->materials->map(fn ($mat) => [
                        'uuid' => $mat->uuid,
                        'type' => $mat->type,
                        'category' => $mat->category,
                        'title' => $mat->title,
                        'description' => $mat->description,
                        'url' => $mat->url,
                        'mime_type' => $mat->mime_type,
                        'file_size' => $mat->file_size,
                        'position' => $mat->position,
                        'metadata' => $mat->metadata,
                        // Pre-signed S3 URL (23h cached) for Microsoft Office Online viewer.
                        // Viewer fetches the file server-side so it bypasses our auth middleware.
                        'office_viewer_url' => in_array($mat->type, ['document_word', 'document_excel', 'document_powerpoint'])
                            ? $this->signedUrl($mat->url)
                            : null,
                    ]),
                ]),
            ]);
            $base['final_assessment'] = $c->finalAssessment ? [
                'uuid' => $c->finalAssessment->uuid,
                'name' => $c->finalAssessment->name,
                'status' => $c->finalAssessment->status,
            ] : null;
        }

        return $base;
    }
}
