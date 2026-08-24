<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS 3.1 System Administrator "Approve courses".
 * Trainer submits course → admin approves (published) or rejects (with reason).
 */
class CourseApprovalController extends Controller
{
    /** GET /api/v1/admin/course-approvals — pending queue */
    public function pending(Request $request): JsonResponse
    {
        $courses = Course::with('instructor:id,uuid,email')
            ->withCount('modules')
            ->where('status', 'pending_approval')
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $courses->getCollection()->map(fn ($c) => [
                'uuid' => $c->uuid,
                'title' => $c->title,
                'category' => $c->category,
                'level' => $c->level,
                'instructor' => ['email' => $c->instructor?->email],
                'modules_count' => $c->modules_count,
                'submitted_at' => $c->updated_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
            ],
        ]);
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
        return $this->success(['uuid' => $course->uuid, 'status' => 'rejected'], 'Course rejected');
    }
}
