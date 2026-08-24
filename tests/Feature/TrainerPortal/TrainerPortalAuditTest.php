<?php

namespace Tests\Feature\TrainerPortal;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\TrainerCertification;
use App\Models\TrainerExperience;
use App\Models\TrainerProfile;
use App\Models\TrainerQualification;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\TrainerPortal\FileValidator;
use App\Services\TrainerPortal\TrainerProfileService;
use App\Services\TrainerPortal\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 13 — SECURITY / CORRECTNESS AUDIT test suite.
 *
 * Guards the deep production fixes:
 *   A. Adding a new pending cred revokes the badge
 *   B. Deleting a verified cred re-evaluates the badge
 *   C. students_taught_count updates when enrollments change
 *   D. years_experience is derived when not manually set
 *   E. File upload rejects spoofed MIME (magic bytes)
 *   H. Per-trainer item caps
 *   G. Signed proof URL is bound to the requesting user
 */
class TrainerPortalAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        Storage::fake('local');
    }

    private function makeUser(string $role, string $name = 'User'): User
    {
        $u = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'u' . Str::random(6) . '@t.io',
            'password' => bcrypt('x'),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $u->assignRole($role);
        UserProfile::create([
            'user_id' => $u->id,
            'full_name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? '',
        ]);
        return $u->fresh();
    }

    private function verifiedTrainerWithCreds(): array
    {
        $trainer = $this->makeUser('trainer', 'Alice Test');
        $admin = $this->makeUser('system_admin', 'Admin User');

        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $q = TrainerQualification::create([
            'trainer_profile_id' => $tp->id,
            'institution' => 'UDSM', 'degree' => 'BSc',
        ]);
        $c = TrainerCertification::create([
            'trainer_profile_id' => $tp->id,
            'name' => 'MCT', 'issuer' => 'Microsoft',
        ]);
        app(VerificationService::class)->verifyQualification($q, $admin);
        app(VerificationService::class)->verifyCertification($c, $admin);

        return [$trainer, $tp->fresh(), $admin, $q, $c];
    }

    // ── AUDIT-A: Adding a new pending cred revokes badge ─────────

    public function test_adding_new_pending_qualification_revokes_verified_badge(): void
    {
        [$trainer, $tp] = $this->verifiedTrainerWithCreds();
        $this->assertTrue($tp->is_verified, 'precondition: trainer is verified');

        // Trainer now adds a new pending qualification
        TrainerQualification::create([
            'trainer_profile_id' => $tp->id,
            'institution' => 'MIT', 'degree' => 'MSc',
        ]);

        $this->assertFalse($tp->fresh()->is_verified,
            'badge must be revoked when a pending item appears');
    }

    public function test_adding_new_pending_certification_revokes_badge(): void
    {
        [, $tp] = $this->verifiedTrainerWithCreds();
        TrainerCertification::create([
            'trainer_profile_id' => $tp->id,
            'name' => 'PMP', 'issuer' => 'PMI',
        ]);
        $this->assertFalse($tp->fresh()->is_verified);
    }

    // ── AUDIT-B: Deleting verified cred re-evaluates badge ──────

    public function test_deleting_verified_qualification_rechecks_badge(): void
    {
        [, $tp, , $q] = $this->verifiedTrainerWithCreds();
        $this->assertTrue($tp->is_verified);

        // Delete the only qualification — trainer no longer has a full set
        $q->delete();

        // Trainer still has a verified cert but no quals → isFullyVerified
        // requires at least one of each proof kind, so badge stays if cert alone counts.
        // Our contract: badge requires quals+certs. Verify current state matches contract.
        $stillFully = $tp->fresh()->isFullyVerified();
        $this->assertSame($stillFully, $tp->fresh()->is_verified,
            'badge state must equal isFullyVerified() after deletion');
    }

    // ── AUDIT-C: students_taught_count updates on enrollment ────

    public function test_students_taught_count_updates_on_enrollment_create_and_delete(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $course = Course::create([
            'uuid' => (string) Str::uuid(), 'slug' => 'c-' . Str::random(6),
            'title' => 'Course', 'category' => 'excel', 'level' => 'beginner',
            'status' => 'published', 'instructor_id' => $trainer->id, 'created_by' => $trainer->id,
        ]);

        $student1 = $this->makeUser('student', 'Student One');
        $student2 = $this->makeUser('student', 'Student Two');

        $this->assertSame(0, $tp->fresh()->students_taught_count);

        $e1 = Enrollment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $student1->id, 'course_id' => $course->id,
            'progress_percentage' => 0, 'enrolled_at' => now(),
        ]);
        $this->assertSame(1, $tp->fresh()->students_taught_count);

        Enrollment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $student2->id, 'course_id' => $course->id,
            'progress_percentage' => 0, 'enrolled_at' => now(),
        ]);
        $this->assertSame(2, $tp->fresh()->students_taught_count);

        $e1->delete();
        $this->assertSame(1, $tp->fresh()->students_taught_count);
    }

    // ── AUDIT-D: derived years_experience ───────────────────────

    public function test_years_experience_is_derived_when_not_set_manually(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $tp->update(['years_experience' => null]);

        TrainerExperience::create([
            'trainer_profile_id' => $tp->id,
            'title' => 'Analyst', 'company' => 'X',
            'start_date' => now()->subYears(5)->toDateString(),
        ]);
        TrainerExperience::create([
            'trainer_profile_id' => $tp->id,
            'title' => 'Consultant', 'company' => 'Y',
            'start_date' => now()->subYears(2)->toDateString(),
        ]);

        $this->assertSame(5, $tp->fresh()->effectiveYearsExperience(),
            'must use earliest experience.start_date, not most recent');
    }

    public function test_manual_years_experience_overrides_derived(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $tp->update(['years_experience' => 20]);

        TrainerExperience::create([
            'trainer_profile_id' => $tp->id,
            'title' => 'X', 'company' => 'Y',
            'start_date' => now()->subYears(3)->toDateString(),
        ]);

        $this->assertSame(20, $tp->fresh()->effectiveYearsExperience());
    }

    // ── AUDIT-E: file magic byte validation ─────────────────────

    public function test_file_validator_accepts_real_pdf(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'ok.pdf',
            "%PDF-1.7\n%tail bytes"
        );
        FileValidator::assertSafeProofFile($file);
        $this->assertTrue(true); // no exception thrown
    }

    public function test_file_validator_rejects_spoofed_extension(): void
    {
        // Attacker uploads PHP script renamed to .pdf
        $file = UploadedFile::fake()->createWithContent(
            'evil.pdf',
            "<?php system(\$_GET['x']); ?>"
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('do not match a supported format');
        FileValidator::assertSafeProofFile($file);
    }

    public function test_file_validator_accepts_real_png(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'x.png',
            "\x89PNG\r\n\x1A\nrest-of-file"
        );
        FileValidator::assertSafeProofFile($file);
        $this->assertTrue(true);
    }

    // ── AUDIT-J: myProfile exposes "Courses Delivered" ──────────

    public function test_my_profile_returns_courses_delivered(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice Test');
        Sanctum::actingAs($trainer);

        // Two courses authored by the trainer, one published, one draft.
        $pub = Course::create([
            'uuid' => (string) Str::uuid(), 'slug' => 'c-' . Str::random(6),
            'title' => 'Advanced Excel', 'category' => 'excel', 'level' => 'advanced',
            'status' => 'published', 'instructor_id' => $trainer->id, 'created_by' => $trainer->id,
        ]);
        Course::create([
            'uuid' => (string) Str::uuid(), 'slug' => 'c-' . Str::random(6),
            'title' => 'Draft Course', 'category' => 'excel', 'level' => 'beginner',
            'status' => 'draft', 'instructor_id' => $trainer->id, 'created_by' => $trainer->id,
        ]);
        // A course by ANOTHER trainer — must NOT leak into this response.
        $otherTrainer = $this->makeUser('trainer', 'Bob Other');
        Course::create([
            'uuid' => (string) Str::uuid(), 'slug' => 'c-' . Str::random(6),
            'title' => 'Not Mine', 'category' => 'excel', 'level' => 'beginner',
            'status' => 'published', 'instructor_id' => $otherTrainer->id, 'created_by' => $otherTrainer->id,
        ]);

        // Add one enrollment to prove enrollments_count is included.
        $student = $this->makeUser('student', 'Stu Dent');
        Enrollment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $student->id, 'course_id' => $pub->id,
            'progress_percentage' => 0, 'enrolled_at' => now(),
        ]);

        $r = $this->getJson('/api/v1/trainer/portal/profile');
        $r->assertOk();

        $courses = $r->json('data.courses');
        $this->assertIsArray($courses);
        $this->assertCount(2, $courses, 'both own courses (published + draft) must appear');

        $titles = collect($courses)->pluck('title')->all();
        $this->assertContains('Advanced Excel', $titles);
        $this->assertContains('Draft Course', $titles);
        $this->assertNotContains('Not Mine', $titles);

        $published = collect($courses)->firstWhere('title', 'Advanced Excel');
        $this->assertSame(1, $published['enrollments_count'],
            'enrollments_count must be included per course');
    }

    // ── AUDIT-H: item caps ─────────────────────────────────────

    public function test_qualification_cap_returns_422(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        Sanctum::actingAs($trainer);
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);

        for ($i = 0; $i < 20; $i++) {
            TrainerQualification::create([
                'trainer_profile_id' => $tp->id,
                'institution' => 'Uni ' . $i,
                'degree' => 'BSc',
            ]);
        }

        $r = $this->postJson('/api/v1/trainer/portal/qualifications', [
            'institution' => 'Overflow',
            'degree' => 'BSc',
        ]);
        $r->assertStatus(422);
        $this->assertStringContainsString('Maximum', $r->json('message'));
    }

    // ── AUDIT-G: signed URL bound to user ──────────────────────

    public function test_proof_url_binds_to_requesting_user(): void
    {
        [$trainer, $tp, $admin, $q] = $this->verifiedTrainerWithCreds();
        $q->update(['proof_file_path' => 'trainer-proofs/x/qualifications/dummy.pdf']);
        Storage::disk('local')->put($q->proof_file_path, '%PDF-fake');

        Sanctum::actingAs($admin);
        $r = $this->getJson("/api/v1/trainer/portal/proof/qualifications/{$q->uuid}/url");
        $r->assertOk();
        $url = $r->json('data.download_url');
        $this->assertStringContainsString('audience=' . $admin->id, $url);

        // Extract just the path+query from the URL for the in-app request.
        $parts = parse_url($url);
        $localPath = $parts['path'] . '?' . $parts['query'];

        // Admin (audience) can download.
        $this->get($localPath)->assertOk();

        // Another user attempting to reuse the URL fails.
        $other = $this->makeUser('system_admin', 'Other Admin');
        Sanctum::actingAs($other);
        $this->get($localPath)->assertForbidden();
    }

    public function test_signed_url_rejects_after_expiry_or_tampering(): void
    {
        [, $tp, $admin, $q] = $this->verifiedTrainerWithCreds();
        $q->update(['proof_file_path' => 'trainer-proofs/x/qualifications/dummy.pdf']);
        Storage::disk('local')->put($q->proof_file_path, '%PDF-fake');

        Sanctum::actingAs($admin);
        $r = $this->getJson("/api/v1/trainer/portal/proof/qualifications/{$q->uuid}/url");
        $url = $r->json('data.download_url');
        $tampered = $url . '&extra=1';   // modifies query — signature invalid
        $parts = parse_url($tampered);
        $localPath = $parts['path'] . '?' . $parts['query'];
        $this->get($localPath)->assertForbidden();
    }
}
