<?php

namespace App\Http\Controllers\Api\V1\Trainer;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trainer's enrolled students — one record per enrollment (SRS 3.2 "Monitor progress").
 */
class MyStudentsController extends Controller
{
    /** GET /api/v1/trainer/my-students */
    public function index(Request $request): JsonResponse
    {
        $trainer = $request->user();
        $search  = $request->query('search');
        $courseUuid = $request->query('course_uuid');

        $query = Enrollment::whereHas('course', fn ($q) => $q->where('instructor_id', $trainer->id))
            ->with([
                'user:id,uuid,email,username',
                'user.profile:user_id,first_name,last_name,full_name,profile_picture',
                'course:id,uuid,title,slug',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($u) use ($search) {
                    $u->where('email', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%");
                })->orWhereHas('user.profile', function ($p) use ($search) {
                    $p->where('full_name', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%");
                });
            });
        }

        if ($courseUuid) {
            $query->whereHas('course', fn ($q) => $q->where('uuid', $courseUuid));
        }

        $paginated = $query->orderByDesc('enrolled_at')->paginate((int) $request->query('per_page', 20));

        $total     = Enrollment::whereHas('course', fn ($q) => $q->where('instructor_id', $trainer->id))->count();
        $active    = Enrollment::whereHas('course', fn ($q) => $q->where('instructor_id', $trainer->id))->whereNull('completed_at')->count();
        $completed = Enrollment::whereHas('course', fn ($q) => $q->where('instructor_id', $trainer->id))->whereNotNull('completed_at')->count();

        return $this->success([
            'data' => $paginated->getCollection()->map(fn ($e) => [
                'uuid'                => $e->uuid ?? null,
                'enrolled_at'         => $e->enrolled_at?->toDateString(),
                'progress_percentage' => (int) ($e->progress_percentage ?? 0),
                'completed_at'        => $e->completed_at?->toDateString(),
                'student' => [
                    'uuid'       => $e->user?->uuid,
                    'email'      => $e->user?->email,
                    'username'   => $e->user?->username,
                    'name'       => $e->user?->profile?->full_name
                        ?? trim(($e->user?->profile?->first_name ?? '') . ' ' . ($e->user?->profile?->last_name ?? ''))
                        ?: ($e->user?->username ?? $e->user?->email),
                    'avatar_url' => $e->user?->profile?->profile_picture,
                ],
                'course' => [
                    'uuid'  => $e->course?->uuid,
                    'title' => $e->course?->title,
                    'slug'  => $e->course?->slug,
                ],
            ]),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
            'summary' => [
                'total_enrollments' => $total,
                'active'            => $active,
                'completed'         => $completed,
            ],
        ]);
    }
}
