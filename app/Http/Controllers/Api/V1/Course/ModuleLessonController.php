<?php

namespace App\Http\Controllers\Api\V1\Course;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS 4.2: Course → Modules → Lessons.
 * Nested CRUD for modules and lessons. Trainer (owner) or admin can mutate.
 */
class ModuleLessonController extends Controller
{
    /** POST /api/v1/courses/{course:uuid}/modules */
    public function storeModule(Course $course, Request $request): JsonResponse
    {
        $this->authorizeCourse($course, $request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'position' => ['nullable', 'integer'],
        ]);
        $module = $course->modules()->create([
            ...$data,
            'position' => $data['position'] ?? ($course->modules()->max('position') + 1),
        ]);
        return $this->success($this->transformModule($module), 'Module created', 201);
    }

    /** PATCH /api/v1/modules/{module:uuid} */
    public function updateModule(CourseModule $module, Request $request): JsonResponse
    {
        $this->authorizeCourse($module->course, $request);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'position' => ['sometimes', 'integer'],
        ]);
        $module->update($data);
        return $this->success($this->transformModule($module->fresh()), 'Module updated');
    }

    /** DELETE /api/v1/modules/{module:uuid} */
    public function destroyModule(CourseModule $module, Request $request): JsonResponse
    {
        $this->authorizeCourse($module->course, $request);
        $module->delete();
        return $this->success(null, 'Module deleted');
    }

    /** POST /api/v1/modules/{module:uuid}/attach-quiz — SRS "Module Contains Quiz" */
    public function attachQuiz(CourseModule $module, Request $request): JsonResponse
    {
        $this->authorizeCourse($module->course, $request);
        $data = $request->validate([
            'quiz_uuid' => ['required', 'exists:quizzes,uuid'],
        ]);
        $quiz = \App\Models\Quiz::where('uuid', $data['quiz_uuid'])->firstOrFail();

        // Only owner of the quiz (or admin) can attach it
        if ($quiz->created_by !== $request->user()->id && !$request->user()->hasRole('system_admin')) {
            abort(403, 'Not your quiz.');
        }
        $quiz->update(['course_module_id' => $module->id]);
        return $this->success(['quiz_uuid' => $quiz->uuid, 'module_uuid' => $module->uuid], 'Quiz attached');
    }

    /** POST /api/v1/quizzes/{quiz:uuid}/detach — remove from module */
    public function detachQuiz(\App\Models\Quiz $quiz, Request $request): JsonResponse
    {
        if ($quiz->created_by !== $request->user()->id && !$request->user()->hasRole('system_admin')) {
            abort(403, 'Not your quiz.');
        }
        $quiz->update(['course_module_id' => null]);
        return $this->success(null, 'Quiz detached');
    }

    /** POST /api/v1/courses/{course:uuid}/reorder-modules */
    public function reorderModules(Course $course, Request $request): JsonResponse
    {
        $this->authorizeCourse($course, $request);
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'exists:course_modules,uuid'],
        ]);
        foreach ($data['order'] as $i => $uuid) {
            CourseModule::where('uuid', $uuid)->where('course_id', $course->id)->update(['position' => $i]);
        }
        return $this->success(null, 'Modules reordered');
    }

    /** POST /api/v1/modules/{module:uuid}/reorder-lessons */
    public function reorderLessons(CourseModule $module, Request $request): JsonResponse
    {
        $this->authorizeCourse($module->course, $request);
        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'exists:lessons,uuid'],
        ]);
        foreach ($data['order'] as $i => $uuid) {
            Lesson::where('uuid', $uuid)->where('course_module_id', $module->id)->update(['position' => $i]);
        }
        return $this->success(null, 'Lessons reordered');
    }

    /** POST /api/v1/modules/{module:uuid}/lessons */
    public function storeLesson(CourseModule $module, Request $request): JsonResponse
    {
        $this->authorizeCourse($module->course, $request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:1024'],
            'pdf_url' => ['nullable', 'string', 'max:1024'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'position' => ['nullable', 'integer'],
        ]);
        $lesson = $module->lessons()->create([
            ...$data,
            'position' => $data['position'] ?? ($module->lessons()->max('position') + 1),
        ]);
        return $this->success($this->transformLesson($lesson), 'Lesson created', 201);
    }

    /** PATCH /api/v1/lessons/{lesson:uuid} */
    public function updateLesson(Lesson $lesson, Request $request): JsonResponse
    {
        $this->authorizeCourse($lesson->module->course, $request);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'content' => ['sometimes', 'nullable', 'string'],
            'video_url' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'pdf_url' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'position' => ['sometimes', 'integer'],
        ]);
        $lesson->update($data);
        return $this->success($this->transformLesson($lesson->fresh()), 'Lesson updated');
    }

    /** DELETE /api/v1/lessons/{lesson:uuid} */
    public function destroyLesson(Lesson $lesson, Request $request): JsonResponse
    {
        $this->authorizeCourse($lesson->module->course, $request);
        $lesson->delete();
        return $this->success(null, 'Lesson deleted');
    }

    // --- Helpers ---

    private function authorizeCourse(Course $course, Request $request): void
    {
        $user = $request->user();
        if ($course->instructor_id !== $user->id && !$user->hasRole('system_admin')) {
            abort(403, 'Not your course.');
        }
    }

    private function transformModule(CourseModule $m): array
    {
        return [
            'uuid' => $m->uuid,
            'title' => $m->title,
            'description' => $m->description,
            'position' => $m->position,
            'course_uuid' => $m->course?->uuid,
        ];
    }

    private function transformLesson(Lesson $l): array
    {
        return [
            'uuid' => $l->uuid,
            'title' => $l->title,
            'description' => $l->description,
            'content' => $l->content,
            'video_url' => $l->video_url,
            'pdf_url' => $l->pdf_url,
            'duration_seconds' => $l->duration_seconds,
            'position' => $l->position,
            'module_uuid' => $l->module?->uuid,
        ];
    }
}
