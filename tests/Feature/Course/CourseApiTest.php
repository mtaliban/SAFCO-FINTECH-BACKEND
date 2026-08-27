<?php

namespace Tests\Feature\Course;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 2 — Course Management API tests.
 *
 * Covers: CRUD lifecycle, role guards, enrollment, admin approval,
 * student visibility, and course submission flow.
 */
class CourseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client', 'facilitator'] as $r) {
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

    private function makeCourse(User $trainer, array $extra = []): Course
    {
        return Course::create(array_merge([
            'uuid'          => (string) Str::uuid(),
            'slug'          => 'course-' . Str::random(6),
            'title'         => 'Test Course',
            'category'      => 'excel',
            'level'         => 'beginner',
            'status'        => 'draft',
            'instructor_id' => $trainer->id,
            'created_by'    => $trainer->id,
        ], $extra));
    }

    // ── Browse ─────────────────────────────────────────────────────────────────

    public function test_courses_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/courses')->assertStatus(401);
    }

    public function test_student_only_sees_published_courses(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');

        $this->makeCourse($trainer, ['status' => 'draft']);
        $this->makeCourse($trainer, ['status' => 'published']);

        Sanctum::actingAs($student);

        $res = $this->getJson('/api/v1/courses');
        $res->assertOk();

        $courses = collect($res->json('data.data'));
        $this->assertTrue($courses->every(fn ($c) => $c['status'] === 'published'));
    }

    public function test_admin_sees_all_courses(): void
    {
        $trainer = $this->makeUser();
        $admin   = $this->makeUser('system_admin');

        $this->makeCourse($trainer, ['status' => 'draft']);
        $this->makeCourse($trainer, ['status' => 'published']);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/v1/courses');
        $this->assertGreaterThanOrEqual(2, count($res->json('data.data')));
    }

    public function test_trainer_sees_own_drafts_and_published_others(): void
    {
        $trainer = $this->makeUser();
        $other   = $this->makeUser();
        Sanctum::actingAs($trainer);

        $myDraft    = $this->makeCourse($trainer, ['status' => 'draft']);
        $otherDraft = $this->makeCourse($other, ['status' => 'draft']);
        $published  = $this->makeCourse($other, ['status' => 'published']);

        $res = $this->getJson('/api/v1/courses');
        $uuids = collect($res->json('data.data'))->pluck('uuid');

        $this->assertTrue($uuids->contains($myDraft->uuid));
        $this->assertFalse($uuids->contains($otherDraft->uuid));
        $this->assertTrue($uuids->contains($published->uuid));
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function test_trainer_can_create_course(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);

        $res = $this->postJson('/api/v1/courses', [
            'title'    => 'Excel for Finance',
            'category' => 'excel',
            'level'    => 'beginner',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.title', 'Excel for Finance')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('courses', ['title' => 'Excel for Finance']);
    }

    public function test_student_cannot_create_course(): void
    {
        $student = $this->makeUser('student');
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/courses', [
            'title'    => 'My Course',
            'category' => 'excel',
        ])->assertStatus(403);
    }

    public function test_course_create_validates_category(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);

        $this->postJson('/api/v1/courses', [
            'title'    => 'Test',
            'category' => 'invalid_category',
        ])->assertStatus(422);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_course_show_returns_details(): void
    {
        $trainer = $this->makeUser();
        $course  = $this->makeCourse($trainer, ['status' => 'published']);
        Sanctum::actingAs($trainer);

        $this->getJson("/api/v1/courses/{$course->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $course->uuid);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_trainer_can_update_own_course(): void
    {
        $trainer = $this->makeUser();
        $course  = $this->makeCourse($trainer);
        Sanctum::actingAs($trainer);

        $this->patchJson("/api/v1/courses/{$course->uuid}", [
            'title' => 'Updated Title',
        ])->assertOk()
          ->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_trainer_cannot_update_another_trainers_course(): void
    {
        $owner  = $this->makeUser();
        $other  = $this->makeUser();
        $course = $this->makeCourse($owner);

        Sanctum::actingAs($other);

        $this->patchJson("/api/v1/courses/{$course->uuid}", [
            'title' => 'Hijacked',
        ])->assertStatus(403);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_trainer_can_delete_own_draft_course(): void
    {
        $trainer = $this->makeUser();
        $course  = $this->makeCourse($trainer);
        Sanctum::actingAs($trainer);

        $this->deleteJson("/api/v1/courses/{$course->uuid}")->assertOk();
        $this->assertSoftDeleted('courses', ['id' => $course->id]);
    }

    // ── Submit for approval ───────────────────────────────────────────────────

    public function test_trainer_can_submit_course_for_review(): void
    {
        $trainer = $this->makeUser();
        $course  = $this->makeCourse($trainer, ['status' => 'draft']);

        // submit requires at least one module
        CourseModule::create([
            'uuid'      => (string) Str::uuid(),
            'course_id' => $course->id,
            'title'     => 'Module 1',
            'position'  => 1,
        ]);

        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/courses/{$course->uuid}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');
    }

    // ── Admin approval ────────────────────────────────────────────────────────

    public function test_admin_can_approve_pending_course(): void
    {
        $trainer = $this->makeUser();
        $admin   = $this->makeUser('system_admin');
        $course  = $this->makeCourse($trainer, ['status' => 'pending_approval']);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/courses/{$course->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_admin_can_reject_pending_course(): void
    {
        $trainer = $this->makeUser();
        $admin   = $this->makeUser('system_admin');
        $course  = $this->makeCourse($trainer, ['status' => 'pending_approval']);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/courses/{$course->uuid}/reject", [
            'reason' => 'Missing learning objectives',
        ])->assertOk()
          ->assertJsonPath('data.status', 'rejected');
    }

    public function test_trainer_cannot_approve_courses(): void
    {
        $trainer = $this->makeUser();
        $course  = $this->makeCourse($trainer, ['status' => 'pending_approval']);

        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/admin/courses/{$course->uuid}/approve")
            ->assertStatus(403);
    }

    // ── Enrollment ────────────────────────────────────────────────────────────

    public function test_student_can_enroll_in_free_published_course(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        $course  = $this->makeCourse($trainer, ['status' => 'published', 'price_tzs' => 0]);

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/courses/{$course->uuid}/enroll")
            ->assertStatus(201);

        $this->assertDatabaseHas('enrollments', [
            'user_id'   => $student->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_student_cannot_enroll_in_paid_course_without_payment(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        $course  = $this->makeCourse($trainer, ['status' => 'published', 'price_tzs' => 50_000]);

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/courses/{$course->uuid}/enroll")
            ->assertStatus(402);
    }

    public function test_double_enroll_is_idempotent_and_returns_201(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        $course  = $this->makeCourse($trainer, ['status' => 'published', 'price_tzs' => 0]);

        Sanctum::actingAs($student);

        // First enrollment
        $this->postJson("/api/v1/courses/{$course->uuid}/enroll")->assertStatus(201);

        // Second call — firstOrCreate returns the existing row; still 201 (idempotent)
        $this->postJson("/api/v1/courses/{$course->uuid}/enroll")->assertStatus(201);

        // Only one enrollment row should exist
        $this->assertCount(
            1,
            Enrollment::where('user_id', $student->id)->where('course_id', $course->id)->get()
        );
    }

    public function test_student_enrollment_list(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');
        $course  = $this->makeCourse($trainer, ['status' => 'published', 'price_tzs' => 0]);

        Enrollment::create([
            'uuid'      => (string) Str::uuid(),
            'user_id'   => $student->id,
            'course_id' => $course->id,
            'status'    => 'active',
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/v1/student/my-enrollments')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ── Course search/filter ──────────────────────────────────────────────────

    public function test_courses_can_be_filtered_by_category(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);

        $this->makeCourse($trainer, ['category' => 'excel', 'status' => 'published']);
        $this->makeCourse($trainer, ['category' => 'finance', 'status' => 'published']);

        $res = $this->getJson('/api/v1/courses?category=excel');
        $courses = collect($res->json('data.data'));
        $this->assertTrue($courses->every(fn ($c) => $c['category'] === 'excel'));
    }
}
