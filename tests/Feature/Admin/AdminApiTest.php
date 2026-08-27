<?php

namespace Tests\Feature\Admin;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin API tests — user management, certificates, audit log, stats.
 */
class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $role = 'student', array $extra = []): User
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
            'slug'          => 'c-' . Str::random(6),
            'title'         => 'Course ' . Str::random(4),
            'category'      => 'excel',
            'level'         => 'beginner',
            'status'        => 'draft',
            'instructor_id' => $trainer->id,
            'created_by'    => $trainer->id,
        ], $extra));
    }

    // ── User Management ────────────────────────────────────────────────────────

    public function test_admin_can_list_all_users(): void
    {
        $admin = $this->makeUser('system_admin');
        $this->makeUser('student');
        $this->makeUser('trainer');

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/v1/admin/users');
        $res->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_trainer_cannot_list_users(): void
    {
        $trainer = $this->makeUser('trainer');
        Sanctum::actingAs($trainer);

        $this->getJson('/api/v1/admin/users')->assertStatus(403);
    }

    public function test_student_cannot_list_users(): void
    {
        $student = $this->makeUser('student');
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/admin/users')->assertStatus(403);
    }

    public function test_admin_can_view_single_user(): void
    {
        $admin   = $this->makeUser('system_admin');
        $student = $this->makeUser('student');

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/users/{$student->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $student->uuid);
    }

    public function test_admin_can_suspend_user(): void
    {
        $admin   = $this->makeUser('system_admin');
        $student = $this->makeUser('student');

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$student->uuid}/status", [
            'status' => 'suspended',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id'     => $student->id,
            'status' => 'suspended',
        ]);
    }

    public function test_admin_can_reactivate_user(): void
    {
        $admin   = $this->makeUser('system_admin');
        $student = $this->makeUser('student', ['status' => 'suspended']);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$student->uuid}/status", [
            'status' => 'active',
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $student->id, 'status' => 'active']);
    }

    public function test_admin_can_delete_user(): void
    {
        $admin   = $this->makeUser('system_admin');
        $student = $this->makeUser('student');

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/admin/users/{$student->uuid}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $student->id]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = $this->makeUser('system_admin');
        Sanctum::actingAs($admin);

        // Policy::delete() returns false for self — authorization denied (403)
        $this->deleteJson("/api/v1/admin/users/{$admin->uuid}")
            ->assertStatus(403);
    }

    // ── Course Approval ────────────────────────────────────────────────────────

    public function test_admin_can_list_pending_course_approvals(): void
    {
        $admin   = $this->makeUser('system_admin');
        $trainer = $this->makeUser('trainer');
        $this->makeCourse($trainer, ['status' => 'pending_approval']);

        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/v1/admin/course-approvals');
        $res->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_non_admin_cannot_see_course_approvals(): void
    {
        $trainer = $this->makeUser('trainer');
        Sanctum::actingAs($trainer);

        $this->getJson('/api/v1/admin/course-approvals')->assertStatus(403);
    }

    // ── Platform Stats ─────────────────────────────────────────────────────────

    public function test_admin_can_view_platform_stats(): void
    {
        $admin = $this->makeUser('system_admin');
        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/v1/admin/stats');
        $res->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_student_cannot_view_platform_stats(): void
    {
        $student = $this->makeUser('student');
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/admin/stats')->assertStatus(403);
    }

    // ── Audit Log ─────────────────────────────────────────────────────────────

    public function test_admin_can_view_audit_log(): void
    {
        $admin = $this->makeUser('system_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/audit-log')->assertOk();
    }

    public function test_non_admin_cannot_view_audit_log(): void
    {
        $trainer = $this->makeUser('trainer');
        Sanctum::actingAs($trainer);

        $this->getJson('/api/v1/admin/audit-log')->assertStatus(403);
    }

    // ── Certificate Administration ─────────────────────────────────────────────

    public function test_admin_can_list_all_certificates(): void
    {
        $admin = $this->makeUser('system_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/certificates')->assertOk();
    }

    public function test_admin_can_revoke_certificate(): void
    {
        $admin   = $this->makeUser('system_admin');
        $student = $this->makeUser('student');
        $trainer = $this->makeUser('trainer');
        $course  = $this->makeCourse($trainer, ['status' => 'published']);

        $cert = $this->makeCert($student, $course);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/certificates/{$cert->uuid}/revoke", [
            'reason' => 'Academic dishonesty',
        ])->assertOk();

        $this->assertDatabaseHas('certificates', [
            'id'     => $cert->id,
            'status' => 'revoked',
        ]);
    }

    public function test_student_certificate_show_is_accessible(): void
    {
        $student = $this->makeUser('student');
        $trainer = $this->makeUser('trainer');
        $course  = $this->makeCourse($trainer, ['status' => 'published']);
        $cert    = $this->makeCert($student, $course);

        Sanctum::actingAs($student);

        $this->getJson("/api/v1/certificates/{$cert->uuid}")
            ->assertOk()
            ->assertJsonPath('data.cert_number', $cert->cert_number);
    }

    // ── Public Certificate Verification ───────────────────────────────────────

    public function test_public_certificate_verification_endpoint(): void
    {
        $student = $this->makeUser('student');
        $trainer = $this->makeUser('trainer');
        $course  = $this->makeCourse($trainer, ['status' => 'published']);
        $cert    = $this->makeCert($student, $course);

        // This endpoint is public — no auth needed
        $this->getJson("/api/v1/verify/certificate/{$cert->cert_number}")
            ->assertOk()
            ->assertJsonPath('data.cert_number', $cert->cert_number);
    }

    public function test_revoked_certificate_shows_revoked_status_in_verification(): void
    {
        $student = $this->makeUser('student');
        $trainer = $this->makeUser('trainer');
        $course  = $this->makeCourse($trainer, ['status' => 'published']);
        $cert    = $this->makeCert($student, $course, ['status' => 'revoked', 'revoked_at' => now()]);

        $this->getJson("/api/v1/verify/certificate/{$cert->cert_number}")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
    }

    private function makeCert(User $student, Course $course, array $extra = []): Certificate
    {
        $certNumber = 'SAFCO-2026-' . strtoupper(Str::random(8));
        $certService = app(\App\Services\Certificate\CertificateService::class);
        $hash = $certService->buildVerificationHash($certNumber, $student->id, $course->id);

        return Certificate::create(array_merge([
            'uuid'                  => (string) Str::uuid(),
            'cert_number'           => $certNumber,
            'user_id'               => $student->id,
            'course_id'             => $course->id,
            'student_name_snapshot' => 'Test Student',
            'course_title_snapshot' => $course->title,
            'verification_hash'     => $hash,
            'completion_date'       => now()->toDateString(),
            'issued_at'             => now(),
            'status'                => 'active',
        ], $extra));
    }
}
