<?php

namespace Tests\Feature\TrainerPortal;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\TrainerCertification;
use App\Models\TrainerProfile;
use App\Models\TrainerQualification;
use App\Models\TrainerReview;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\TrainerPortal\ReviewService;
use App\Services\TrainerPortal\TrainerProfileService;
use App\Services\TrainerPortal\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 13 — Certified Trainer Portal acceptance tests.
 */
class TrainerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $role, ?string $fullName = null): User
    {
        $u = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'u' . Str::random(6) . '@t.io',
            'password' => bcrypt('secret'),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);
        $u->assignRole($role);
        if ($fullName) {
            UserProfile::create([
                'user_id' => $u->id,
                'full_name' => $fullName,
                'first_name' => explode(' ', $fullName)[0],
                'last_name' => explode(' ', $fullName)[1] ?? '',
            ]);
        }
        return $u->fresh();
    }

    private function makeCourseFor(User $trainer): Course
    {
        return Course::create([
            'uuid' => (string) Str::uuid(),
            'slug' => 'c-' . Str::random(6),
            'title' => 'Test course',
            'category' => 'excel',
            'level' => 'beginner',
            'status' => 'published',
            'instructor_id' => $trainer->id,
            'created_by' => $trainer->id,
        ]);
    }

    private function makeEnrollment(User $student, Course $course, bool $completed = true): Enrollment
    {
        return Enrollment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $student->id,
            'course_id' => $course->id,
            'progress_percentage' => $completed ? 100 : 40,
            'enrolled_at' => now()->subDays(10),
            'completed_at' => $completed ? now() : null,
        ]);
    }

    // ── Slug generation ─────────────────────────────────────────

    public function test_slug_is_generated_from_full_name(): void
    {
        $trainer = $this->makeUser('trainer', 'Amina Hassan');
        $svc = app(TrainerProfileService::class);
        $profile = $svc->getOrCreateFor($trainer);

        $this->assertSame('amina-hassan', $profile->public_slug);
    }

    public function test_slug_avoids_collision(): void
    {
        $t1 = $this->makeUser('trainer', 'Amina Hassan');
        $t2 = $this->makeUser('trainer', 'Amina Hassan');

        $svc = app(TrainerProfileService::class);
        $p1 = $svc->getOrCreateFor($t1);
        $p2 = $svc->getOrCreateFor($t2);

        $this->assertSame('amina-hassan', $p1->public_slug);
        $this->assertNotSame($p1->public_slug, $p2->public_slug);
        $this->assertStringStartsWith('amina-hassan-', $p2->public_slug);
    }

    public function test_get_or_create_is_idempotent(): void
    {
        $trainer = $this->makeUser('trainer', 'Test Trainer');
        $svc = app(TrainerProfileService::class);
        $p1 = $svc->getOrCreateFor($trainer);
        $p2 = $svc->getOrCreateFor($trainer);
        $this->assertSame($p1->id, $p2->id);
    }

    // ── Public directory ─────────────────────────────────────────

    public function test_directory_only_shows_public_trainers(): void
    {
        $publicTrainer = $this->makeUser('trainer', 'Public Trainer');
        $privateTrainer = $this->makeUser('trainer', 'Private Trainer');
        $svc = app(TrainerProfileService::class);
        $svc->getOrCreateFor($publicTrainer)->update(['is_public' => true]);
        $svc->getOrCreateFor($privateTrainer)->update(['is_public' => false]);

        $r = $this->getJson('/api/v1/trainers');
        $r->assertOk();
        $names = collect($r->json('data.data'))->pluck('name')->all();
        $this->assertContains('Public Trainer', $names);
        $this->assertNotContains('Private Trainer', $names);
    }

    public function test_directory_search_by_expertise(): void
    {
        $t = $this->makeUser('trainer', 'Excel Expert');
        app(TrainerProfileService::class)->getOrCreateFor($t)->update([
            'is_public' => true,
            'expertise_areas' => ['excel', 'power_query'],
        ]);
        $other = $this->makeUser('trainer', 'Word Expert');
        app(TrainerProfileService::class)->getOrCreateFor($other)->update([
            'is_public' => true,
            'expertise_areas' => ['word'],
        ]);

        $r = $this->getJson('/api/v1/trainers?expertise=excel');
        $r->assertOk();
        $names = collect($r->json('data.data'))->pluck('name')->all();
        $this->assertContains('Excel Expert', $names);
        $this->assertNotContains('Word Expert', $names);
    }

    public function test_public_profile_hidden_for_private_trainer(): void
    {
        $t = $this->makeUser('trainer', 'Hidden');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($t);
        $tp->update(['is_public' => false]);

        $this->getJson("/api/v1/trainers/{$tp->public_slug}")->assertNotFound();
    }

    public function test_public_profile_visible_for_public_trainer(): void
    {
        $t = $this->makeUser('trainer', 'Visible');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($t);
        $tp->update(['is_public' => true, 'headline' => 'Excel Guru']);

        $r = $this->getJson("/api/v1/trainers/{$tp->public_slug}");
        $r->assertOk();
        $this->assertSame('Excel Guru', $r->json('data.headline'));
    }

    // ── Review gating ──────────────────────────────────────────

    public function test_review_requires_completed_enrollment(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $student = $this->makeUser('student', 'Bob');
        $course = $this->makeCourseFor($trainer);
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $tp->update(['is_public' => true]);

        $this->makeEnrollment($student, $course, completed: false);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('completed');
        app(ReviewService::class)->submit($tp, $student, $course, 5, 'great');
    }

    public function test_review_requires_matching_trainer_for_course(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $otherTrainer = $this->makeUser('trainer', 'Wrong Person');
        $student = $this->makeUser('student', 'Bob');
        $courseByOther = $this->makeCourseFor($otherTrainer);
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);

        $this->makeEnrollment($student, $courseByOther, completed: true);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not delivered');
        app(ReviewService::class)->submit($tp, $student, $courseByOther, 5, 'x');
    }

    public function test_review_updates_trainer_aggregate(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $student1 = $this->makeUser('student', 'Bob');
        $student2 = $this->makeUser('student', 'Carol');
        $course = $this->makeCourseFor($trainer);
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);

        $this->makeEnrollment($student1, $course, true);
        $this->makeEnrollment($student2, $course, true);

        app(ReviewService::class)->submit($tp, $student1, $course, 5, 'excellent');
        app(ReviewService::class)->submit($tp, $student2, $course, 3, 'ok');

        $tp->refresh();
        $this->assertSame(2, $tp->rating_count);
        $this->assertEqualsWithDelta(4.0, (float) $tp->rating_avg, 0.01);
    }

    public function test_review_is_unique_per_student_course(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $student = $this->makeUser('student', 'Bob');
        $course = $this->makeCourseFor($trainer);
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $this->makeEnrollment($student, $course, true);

        // Submit twice — must be an UPDATE not a new row
        app(ReviewService::class)->submit($tp, $student, $course, 5, 'first');
        app(ReviewService::class)->submit($tp, $student, $course, 3, 'updated');

        $this->assertSame(1, TrainerReview::count());
        $this->assertSame(3, TrainerReview::first()->rating);
        $this->assertSame(1, $tp->fresh()->rating_count);
    }

    // ── Verification workflow ────────────────────────────────────

    public function test_admin_can_verify_qualification(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $q = TrainerQualification::create([
            'trainer_profile_id' => $tp->id,
            'institution' => 'UDSM', 'degree' => 'BSc',
        ]);

        $admin = $this->makeUser('system_admin', 'Admin');
        app(VerificationService::class)->verifyQualification($q, $admin);

        $this->assertSame('verified', $q->fresh()->verification_status);
    }

    public function test_badge_issued_when_all_credentials_verified(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $q = TrainerQualification::create([
            'trainer_profile_id' => $tp->id,
            'institution' => 'UDSM', 'degree' => 'BSc',
        ]);
        $c = TrainerCertification::create([
            'trainer_profile_id' => $tp->id,
            'name' => 'MCT', 'issuer' => 'Microsoft',
        ]);
        $admin = $this->makeUser('system_admin');

        $this->assertFalse($tp->fresh()->is_verified);

        app(VerificationService::class)->verifyQualification($q, $admin);
        $this->assertFalse($tp->fresh()->is_verified, 'still one cert pending');

        app(VerificationService::class)->verifyCertification($c, $admin);
        $this->assertTrue($tp->fresh()->is_verified, 'all verified → badge');
    }

    public function test_rejecting_credential_revokes_badge(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $tp->update(['is_verified' => true, 'verified_at' => now()]);

        $q = TrainerQualification::create([
            'trainer_profile_id' => $tp->id,
            'institution' => 'X', 'degree' => 'Y',
            'verification_status' => 'verified',
        ]);
        $admin = $this->makeUser('system_admin');

        app(VerificationService::class)->rejectQualification($q, $admin, 'Fake diploma');
        $this->assertFalse($tp->fresh()->is_verified);
    }

    // ── Auth boundaries ─────────────────────────────────────────

    public function test_non_admin_cannot_verify(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $q = TrainerQualification::create([
            'trainer_profile_id' => $tp->id,
            'institution' => 'X', 'degree' => 'Y',
        ]);

        $someone = $this->makeUser('trainer', 'Someone');
        Sanctum::actingAs($someone);
        $this->postJson("/api/v1/admin/trainer-qualifications/{$q->uuid}/verify")->assertForbidden();
    }

    public function test_student_cannot_review_without_completed_enrollment_via_http(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $student = $this->makeUser('student', 'Bob');
        $course = $this->makeCourseFor($trainer);
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);

        Sanctum::actingAs($student);
        $r = $this->postJson("/api/v1/trainers/{$tp->public_slug}/reviews", [
            'course_uuid' => $course->uuid, 'rating' => 5, 'review_text' => 'nice',
        ]);
        $r->assertStatus(422);
    }

    public function test_student_can_review_after_completion_via_http(): void
    {
        $trainer = $this->makeUser('trainer', 'Alice');
        $student = $this->makeUser('student', 'Bob');
        $course = $this->makeCourseFor($trainer);
        $tp = app(TrainerProfileService::class)->getOrCreateFor($trainer);
        $this->makeEnrollment($student, $course, completed: true);

        Sanctum::actingAs($student);
        $r = $this->postJson("/api/v1/trainers/{$tp->public_slug}/reviews", [
            'course_uuid' => $course->uuid, 'rating' => 4, 'review_text' => 'good',
        ]);
        $r->assertStatus(201);
        $this->assertSame(1, TrainerReview::count());
        $this->assertEquals(4.0, (float) $tp->fresh()->rating_avg);
    }

    // ── Expiring certifications ─────────────────────────────────

    public function test_certification_is_expired_flag(): void
    {
        $tp = app(TrainerProfileService::class)->getOrCreateFor($this->makeUser('trainer', 'Alice'));
        $expired = TrainerCertification::create([
            'trainer_profile_id' => $tp->id,
            'name' => 'X', 'issuer' => 'Y',
            'issue_date' => now()->subYears(3)->toDateString(),
            'expiry_date' => now()->subDays(30)->toDateString(),
        ]);
        $valid = TrainerCertification::create([
            'trainer_profile_id' => $tp->id,
            'name' => 'A', 'issuer' => 'B',
            'issue_date' => now()->subMonths(3)->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($valid->isExpired());
    }
}
