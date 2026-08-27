<?php

namespace Tests\Feature\Course;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 9 — Assignment & Submission API tests.
 *
 * Covers: trainer CRUD, student submit (text), trainer grading,
 * role guards, isolation between students.
 */
class AssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $role = 'trainer', array $extra = []): User
    {
        $u = User::create(array_merge([
            'uuid'              => (string) Str::uuid(),
            'email'             => 'u' . Str::random(6) . '@test.io',
            'password'          => bcrypt('secret'),
            'email_verified_at' => now(),
            'status'            => 'active',
        ], $extra));
        $u->assignRole($role);
        return $u;
    }

    private function makeLesson(User $trainer): array
    {
        $course = Course::create([
            'uuid'          => (string) Str::uuid(),
            'slug'          => 'c-' . Str::random(6),
            'title'         => 'Finance 101',
            'category'      => 'finance',
            'level'         => 'beginner',
            'status'        => 'published',
            'instructor_id' => $trainer->id,
            'created_by'    => $trainer->id,
        ]);

        $module = CourseModule::create([
            'uuid'      => (string) Str::uuid(),
            'course_id' => $course->id,
            'title'     => 'Module 1',
            'position'  => 1,
        ]);

        $lesson = Lesson::create([
            'uuid'             => (string) Str::uuid(),
            'course_module_id' => $module->id,
            'title'            => 'Lesson 1',
            'position'         => 1,
        ]);

        return [$course, $module, $lesson];
    }

    private function enroll(User $student, Course $course): Enrollment
    {
        return Enrollment::create([
            'uuid'      => (string) Str::uuid(),
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'status'    => 'active',
        ]);
    }

    private function makeAssignment(Lesson $lesson, array $extra = []): Assignment
    {
        return Assignment::create(array_merge([
            'uuid'       => (string) Str::uuid(),
            'lesson_id'  => $lesson->id,
            'title'      => 'Test Assignment',
            'max_points' => 100,
        ], $extra));
    }

    // ── Create (trainer) ───────────────────────────────────────────────────────

    public function test_trainer_can_create_assignment_for_their_lesson(): void
    {
        $trainer = $this->makeUser();
        [, , $lesson] = $this->makeLesson($trainer);

        Sanctum::actingAs($trainer);

        $res = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assignments", [
            'title'        => 'Build a budget spreadsheet',
            'instructions' => 'Create an Excel budget for Q1.',
            'max_points'   => 100,
            'due_date'     => now()->addDays(7)->toDateTimeString(),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.title', 'Build a budget spreadsheet');

        $this->assertDatabaseHas('assignments', ['title' => 'Build a budget spreadsheet']);
    }

    public function test_student_cannot_create_assignment(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);

        $this->enroll($student, $course);
        Sanctum::actingAs($student);

        $this->postJson("/api/v1/lessons/{$lesson->uuid}/assignments", [
            'title'      => 'Rogue',
            'max_points' => 100,
        ])->assertStatus(403);
    }

    public function test_trainer_cannot_create_assignment_for_another_trainers_lesson(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        [, , $lesson] = $this->makeLesson($owner);

        Sanctum::actingAs($other);

        $this->postJson("/api/v1/lessons/{$lesson->uuid}/assignments", [
            'title'      => 'Hijacked',
            'max_points' => 50,
        ])->assertStatus(403);
    }

    // ── Update & Delete ────────────────────────────────────────────────────────

    public function test_trainer_can_update_assignment(): void
    {
        $trainer = $this->makeUser();
        [, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);

        Sanctum::actingAs($trainer);

        $this->patchJson("/api/v1/assignments/{$assignment->uuid}", [
            'title' => 'Updated Assignment Title',
        ])->assertOk()
          ->assertJsonPath('data.title', 'Updated Assignment Title');
    }

    public function test_trainer_can_delete_assignment(): void
    {
        $trainer = $this->makeUser();
        [, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);

        Sanctum::actingAs($trainer);

        $this->deleteJson("/api/v1/assignments/{$assignment->uuid}")->assertOk();
        $this->assertSoftDeleted('assignments', ['id' => $assignment->id]);
    }

    // ── Show ───────────────────────────────────────────────────────────────────

    public function test_assignment_show_requires_auth(): void
    {
        $trainer = $this->makeUser();
        [, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);

        $this->getJson("/api/v1/assignments/{$assignment->uuid}")->assertStatus(401);
    }

    public function test_authenticated_user_can_view_assignment(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);
        $this->enroll($student, $course);

        Sanctum::actingAs($student);

        // show returns { data: { assignment: {...}, my_submission: null } }
        $this->getJson("/api/v1/assignments/{$assignment->uuid}")
            ->assertOk()
            ->assertJsonPath('data.assignment.uuid', $assignment->uuid);
    }

    // ── Student submissions (text-based) ──────────────────────────────────────

    public function test_student_can_submit_text_answer(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);
        $this->enroll($student, $course);

        Sanctum::actingAs($student);

        $res = $this->postJson("/api/v1/assignments/{$assignment->uuid}/submit", [
            'answer_text' => 'Here is my answer to the assignment.',
        ]);

        $res->assertStatus(201);

        $this->assertDatabaseHas('assignment_submissions', [
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
        ]);
    }

    public function test_resubmit_overwrites_previous_answer(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);
        $this->enroll($student, $course);

        AssignmentSubmission::create([
            'uuid'          => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
            'answer_text'   => 'First submission',
            'status'        => 'submitted',
        ]);

        Sanctum::actingAs($student);

        // submit uses updateOrCreate — second call updates the existing row (201)
        $this->postJson("/api/v1/assignments/{$assignment->uuid}/submit", [
            'answer_text' => 'Revised answer',
        ])->assertStatus(201);

        // Only one row should exist
        $this->assertCount(
            1,
            AssignmentSubmission::where('assignment_id', $assignment->id)
                ->where('student_id', $student->id)
                ->get()
        );
    }

    public function test_unenrolled_student_cannot_submit(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);

        Sanctum::actingAs($student);

        // Not enrolled → 422 "Enroll in the course first."
        $this->postJson("/api/v1/assignments/{$assignment->uuid}/submit", [
            'answer_text' => 'Answer',
        ])->assertStatus(422);
    }

    public function test_trainer_cannot_submit_to_assignment(): void
    {
        $trainer = $this->makeUser();
        [, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);

        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/assignments/{$assignment->uuid}/submit", [
            'answer_text' => 'Trainer answer',
        ])->assertStatus(403);
    }

    // ── Grading ───────────────────────────────────────────────────────────────

    public function test_trainer_can_grade_submission(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson, ['max_points' => 100]);
        $this->enroll($student, $course);

        $submission = AssignmentSubmission::create([
            'uuid'          => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
            'answer_text'   => 'My budget spreadsheet analysis.',
            'status'        => 'submitted',
        ]);

        Sanctum::actingAs($trainer);

        $res = $this->postJson("/api/v1/submissions/{$submission->uuid}/grade", [
            'grade'    => 85,
            'feedback' => 'Good work, but the Q3 projections need revision.',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.grade', 85)
            ->assertJsonPath('data.status', 'graded');

        $this->assertDatabaseHas('assignment_submissions', [
            'id'     => $submission->id,
            'grade'  => 85,
            'status' => 'graded',
        ]);
    }

    public function test_grade_must_not_exceed_max_points(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson, ['max_points' => 100]);
        $this->enroll($student, $course);

        $submission = AssignmentSubmission::create([
            'uuid'          => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
            'answer_text'   => 'My answer.',
            'status'        => 'submitted',
        ]);

        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/submissions/{$submission->uuid}/grade", [
            'grade' => 150,  // exceeds max_points
        ])->assertStatus(422);
    }

    public function test_student_cannot_grade_submission(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);
        $this->enroll($student, $course);

        $submission = AssignmentSubmission::create([
            'uuid'          => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
            'answer_text'   => 'My answer.',
            'status'        => 'submitted',
        ]);

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/submissions/{$submission->uuid}/grade", [
            'grade' => 90,
        ])->assertStatus(403);
    }

    // ── Submissions list ──────────────────────────────────────────────────────

    public function test_trainer_can_list_submissions_for_their_assignment(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);
        $this->enroll($student, $course);

        AssignmentSubmission::create([
            'uuid'          => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'student_id'    => $student->id,
            'answer_text'   => 'Answer.',
            'status'        => 'submitted',
        ]);

        Sanctum::actingAs($trainer);

        $this->getJson("/api/v1/assignments/{$assignment->uuid}/submissions")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_student_cannot_see_other_students_submissions(): void
    {
        $trainer  = $this->makeUser();
        $student1 = $this->makeUser('student');
        $student2 = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $assignment = $this->makeAssignment($lesson);
        $this->enroll($student1, $course);
        $this->enroll($student2, $course);

        AssignmentSubmission::create([
            'uuid'          => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'student_id'    => $student1->id,
            'answer_text'   => 'Student 1 answer.',
            'status'        => 'submitted',
        ]);

        Sanctum::actingAs($student2);

        // Student 2 calls the submissions list — should not see student 1's data
        $res = $this->getJson("/api/v1/assignments/{$assignment->uuid}/submissions");
        $res->assertOk();

        $submissionUserIds = collect($res->json('data'))->pluck('student_id');
        $this->assertFalse($submissionUserIds->contains($student1->id));
    }

    // ── Student my-assignments ────────────────────────────────────────────────

    public function test_student_can_list_their_assignments(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        [$course, , $lesson] = $this->makeLesson($trainer);
        $this->makeAssignment($lesson);
        $this->enroll($student, $course);

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/student/assignments')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
}
