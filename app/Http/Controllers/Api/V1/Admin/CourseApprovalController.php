<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Notifications\Channels\InAppChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * SRS 3.1 System Administrator "Approve courses".
 * Trainer submits course → admin approves (published) or rejects (with reason).
 */
class CourseApprovalController extends Controller
{
    public function __construct(private readonly InAppChannel $inApp) {}

    /** GET /api/v1/admin/course-approvals — pending queue */
    public function pending(Request $request): JsonResponse
    {
        $courses = Course::with(['instructor:id,uuid,email', 'instructor.profile:user_id,full_name'])
            ->withCount('modules')
            ->where('status', 'pending_approval')
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $courses->getCollection()->map(fn ($c) => [
                'uuid'           => $c->uuid,
                'title'          => $c->title,
                'description'    => $c->description,
                'category'       => $c->category,
                'level'          => $c->level,
                'duration_hours' => $c->duration_hours,
                'thumbnail_url'  => $this->signedUrl($c->thumbnail_url),
                'instructor'     => ['email' => $c->instructor?->email, 'name' => $c->instructor?->profile?->full_name],
                'modules_count'  => $c->modules_count,
                'submitted_at'   => $c->updated_at?->toIso8601String(),
                'stats'          => ['modules' => $c->modules_count],
            ]),
            'meta' => [
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
            ],
        ]);
    }

    /** GET /api/v1/admin/course-approvals/history — approved / rejected courses */
    public function history(Request $request): JsonResponse
    {
        $courses = Course::with([
            'instructor:id,uuid,email',
            'instructor.profile:user_id,full_name',
            'approver:id,uuid,email',
            'approver.profile:user_id,full_name',
        ])
            ->withCount('modules')
            ->whereIn('status', ['published', 'rejected', 'archived'])
            ->latest('updated_at')
            ->paginate((int) $request->query('per_page', 30));

        return $this->success([
            'data' => $courses->getCollection()->map(fn ($c) => [
                'uuid'             => $c->uuid,
                'title'            => $c->title,
                'category'         => $c->category,
                'level'            => $c->level,
                'status'           => $c->status,
                'thumbnail_url'    => $this->signedUrl($c->thumbnail_url),
                'duration_hours'   => $c->duration_hours,
                'instructor'       => [
                    'email' => $c->instructor?->email,
                    'name'  => $c->instructor?->profile?->full_name,
                ],
                'approver'         => [
                    'email' => $c->approver?->email,
                    'name'  => $c->approver?->profile?->full_name,
                ],
                'rejection_reason' => $c->rejection_reason,
                'approved_at'      => $c->approved_at?->toIso8601String(),
                'stats'            => ['modules' => $c->modules_count],
                'updated_at'       => $c->updated_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $courses->currentPage(),
                'last_page'    => $courses->lastPage(),
                'per_page'     => $courses->perPage(),
                'total'        => $courses->total(),
            ],
        ]);
    }

    /** Generate a 24-hour presigned S3 URL (mirrors CourseController::signedUrl). */
    private function signedUrl(?string $url): ?string
    {
        if (!$url) return null;
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) return $url;

        return Cache::remember('signed_url_' . md5($url), now()->addHours(23), function () use ($url) {
            try {
                $bucket = config('filesystems.disks.s3.bucket', '');
                $region = config('filesystems.disks.s3.region', '');
                $path   = null;
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

    /** POST /api/v1/admin/courses/{course:uuid}/approve */
    public function approve(Course $course, Request $request): JsonResponse
    {
        if ($course->status !== 'pending_approval') {
            return $this->error("Course is {$course->status}, not pending_approval.", 422);
        }
        $course->update([
            'status' => 'published',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'published_at' => now(),
            'rejection_reason' => null,
        ]);

        // Notify trainer
        try {
            $trainer = $course->creator;
            if ($trainer) {
                $this->inApp->send($trainer, 'course.approved', [
                    'course_title' => $course->title,
                    'action_url'   => '/trainer/courses/' . $course->uuid . '/edit',
                    'action_label' => 'Angalia Course',
                ]);
            }
        } catch (\Throwable) {}

        return $this->success(['uuid' => $course->uuid, 'status' => 'published'], 'Course approved');
    }

    /** POST /api/v1/admin/courses/{course:uuid}/reject */
    public function reject(Course $course, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        if ($course->status !== 'pending_approval') {
            return $this->error("Course is {$course->status}, not pending_approval.", 422);
        }
        $course->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'],
        ]);

        // Notify trainer
        try {
            $trainer = $course->creator;
            if ($trainer) {
                $this->inApp->send($trainer, 'course.rejected', [
                    'course_title' => $course->title,
                    'reason'       => $data['reason'],
                    'action_url'   => '/trainer/courses/' . $course->uuid . '/edit',
                    'action_label' => 'Hariri na Tuma Tena',
                ]);
            }
        } catch (\Throwable) {}

        return $this->success(['uuid' => $course->uuid, 'status' => 'rejected'], 'Course rejected');
    }
}
