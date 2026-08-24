<?php

namespace Tests\Feature\Dashboard;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 11 — dashboard aggregation acceptance tests.
 *
 * Guards the exact math promised by the SRS: Employees Trained, Completion %,
 * Department Performance, Quiz Performance, Cert counts, revocation semantics,
 * and the per-employee (unbiased) departmental average.
 */
class DashboardAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        cache()->flush();
    }

    private function makeUser(array $overrides = []): User
    {
        $email = $overrides['email'] ?? 'u' . Str::random(6) . '@t.io';
        $u = User::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'email' => $email,
            'password' => bcrypt('secret'),
            'email_verified_at' => now(),
            'status' => 'active',
        ], $overrides));
        return $u;
    }

    private function makeCourse(int $trainerId, array $overrides = []): Course
    {
        return Course::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'slug' => 'c-' . Str::random(6),
            'title' => 'Course ' . Str::random(4),
            'category' => 'excel',
            'instructor_id' => $trainerId,
            'created_by' => $trainerId,
            'status' => 'published',
            'level' => 'beginner',
        ], $overrides));
    }

    private function makeQuiz(int $trainerId, array $overrides = []): Quiz
    {
        return Quiz::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'created_by' => $trainerId,
            'name' => 'Quiz ' . Str::random(4),
            'slug' => 'q-' . Str::random(6),
            'mode' => 'self_paced',
            'status' => 'published',
            'passing_mark_percentage' => 60,
            'number_of_questions' => 10,
        ], $overrides));
    }

    private function makeAttempt(int $userId, int $quizId, int $pct, int $attemptN = 1): QuizAttempt
    {
        $correct = (int) round(10 * $pct / 100);
        return QuizAttempt::create([
            'uuid' => (string) Str::uuid(),
            'quiz_id' => $quizId,
            'user_id' => $userId,
            'attempt_number' => $attemptN,
            'status' => 'completed',
            'total_questions' => 10,
            'correct_answers' => $correct,
            'incorrect_answers' => 10 - $correct,
            'unanswered' => 0,
            'total_score' => $correct * 10,
            'max_possible_score' => 100,
            'percentage' => $pct,
            'passed' => $pct >= 60,
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
            'duration_seconds' => 1200,
            'question_snapshot' => [],
        ]);
    }

    private function seedTrainerCourseQuiz(): array
    {
        $trainer = $this->makeUser();
        $trainer->assignRole('trainer');
        $course = $this->makeCourse($trainer->id);
        $quiz = $this->makeQuiz($trainer->id);
        $course->update(['final_assessment_quiz_id' => $quiz->id]);
        return [$trainer, $course, $quiz];
    }

    private function seedOrgWithEmployees(Course $course, Quiz $quiz): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'M11 Test Corp',
            'slug' => 'm11-test-' . Str::random(4),
            'type' => 'corporate',
            'is_active' => true,
        ]);

        $corp = $this->makeUser(['organization_id' => $org->id]);
        $corp->assignRole('corporate_client');

        $scenarios = [
            ['Finance', 90], ['Finance', 75], ['Finance', 55],
            ['IT', 80], ['IT', 45],
        ];
        $employees = collect();
        foreach ($scenarios as [$dept, $pct]) {
            $u = $this->makeUser(['organization_id' => $org->id]);
            $u->assignRole('student');
            UserProfile::create([
                'user_id' => $u->id,
                'department' => $dept,
                'first_name' => 'Emp',
                'last_name' => (string) $pct,
                'full_name' => "Emp $pct",
            ]);
            Enrollment::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $u->id,
                'course_id' => $course->id,
                'progress_percentage' => $pct >= 60 ? 100 : 50,
                'completed_at' => $pct >= 60 ? now() : null,
                'enrolled_at' => now()->subDays(3),
            ]);
            $this->makeAttempt($u->id, $quiz->id, $pct);
            $employees->push($u);
        }
        return [$org, $corp, $employees];
    }

    public function test_corporate_headline_matches_seeded_state(): void
    {
        [, $course, $quiz] = $this->seedTrainerCourseQuiz();
        [, $corp] = $this->seedOrgWithEmployees($course, $quiz);

        Sanctum::actingAs($corp);
        $r = $this->getJson('/api/v1/corporate/dashboard');
        $r->assertOk();
        $d = $r->json('data');

        $this->assertSame(5, $d['headline']['employees_total']);
        $this->assertSame(3, $d['headline']['employees_trained']);
        $this->assertEquals(60.0, $d['headline']['completion_percent']);
        $this->assertEquals(69.0, $d['headline']['avg_score_percent']);
    }

    public function test_corporate_department_avg_is_unbiased_by_cartesian(): void
    {
        [$trainer, $course, $quiz] = $this->seedTrainerCourseQuiz();
        [, $corp, $employees] = $this->seedOrgWithEmployees($course, $quiz);

        // Give first Finance employee (90%) a SECOND enrollment + attempt.
        // Without per-employee subquery, cartesian join would inflate the avg.
        $alpha = $employees[0];
        $secondCourse = $this->makeCourse($trainer->id, ['title' => 'Second course']);
        Enrollment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $alpha->id,
            'course_id' => $secondCourse->id,
            'progress_percentage' => 50,
            'enrolled_at' => now(),
        ]);
        $this->makeAttempt($alpha->id, $quiz->id, 50, 2);
        cache()->flush();

        Sanctum::actingAs($corp);
        $r = $this->getJson('/api/v1/corporate/dashboard');
        $r->assertOk();

        $finance = collect($r->json('data.by_department'))->firstWhere('department', 'Finance');
        $this->assertNotNull($finance);
        // Alpha per-employee = (90+50)/2 = 70; Bravo = 75; Charlie = 55
        // Correct department avg_score = (70+75+55)/3 = 66.7
        $this->assertEqualsWithDelta(66.7, $finance['avg_score'], 0.1,
            'Department avg_score must be per-employee (unbiased by cartesian join)');
    }

    public function test_revoked_certificates_are_excluded_from_headline(): void
    {
        [, $course, $quiz] = $this->seedTrainerCourseQuiz();
        [, $corp, $employees] = $this->seedOrgWithEmployees($course, $quiz);

        foreach ($employees->take(3) as $emp) {
            Certificate::create([
                'uuid' => (string) Str::uuid(),
                'cert_number' => 'SAFCO-2026-' . Str::upper(Str::random(6)),
                'user_id' => $emp->id,
                'course_id' => $course->id,
                'quiz_attempt_id' => QuizAttempt::where('user_id', $emp->id)->first()->id,
                'student_name_snapshot' => 'Emp Test',
                'course_title_snapshot' => $course->title,
                'completion_date' => now(),
                'issued_at' => now(),
                'score_percentage' => 80,
                'verification_hash' => hash('sha256', Str::random(16)),
                'status' => Certificate::STATUS_ACTIVE,
            ]);
        }

        Sanctum::actingAs($corp);
        cache()->flush();
        $r1 = $this->getJson('/api/v1/corporate/dashboard');
        $this->assertSame(3, $r1->json('data.headline.certificates_earned'));

        Certificate::first()->update([
            'status' => Certificate::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
        cache()->flush();

        $r2 = $this->getJson('/api/v1/corporate/dashboard');
        $this->assertSame(2, $r2->json('data.headline.certificates_earned'));
    }

    public function test_trainer_avg_null_when_no_attempts(): void
    {
        $trainer = $this->makeUser();
        $trainer->assignRole('trainer');
        $this->makeQuiz($trainer->id); // published but no attempts

        Sanctum::actingAs($trainer);
        $r = $this->getJson('/api/v1/trainer/dashboard');
        $r->assertOk();
        $this->assertNull($r->json('data.headline.avg_quiz_score_percent'));
    }

    public function test_trainer_quiz_performance_pass_rate(): void
    {
        [$trainer, $course, $quiz] = $this->seedTrainerCourseQuiz();
        $this->seedOrgWithEmployees($course, $quiz);

        Sanctum::actingAs($trainer);
        $r = $this->getJson('/api/v1/trainer/dashboard');
        $r->assertOk();

        $performed = collect($r->json('data.quiz_performance'))->firstWhere('attempts', 5);
        $this->assertNotNull($performed);
        $this->assertSame(3, $performed['passes']);
        $this->assertEquals(60.0, $performed['pass_rate']);
        $this->assertEquals(69.0, $performed['avg_score']);
    }

    public function test_student_headline(): void
    {
        [, $course, $quiz] = $this->seedTrainerCourseQuiz();
        [, , $employees] = $this->seedOrgWithEmployees($course, $quiz);
        $alpha = $employees[0];

        Sanctum::actingAs($alpha);
        $r = $this->getJson('/api/v1/student/dashboard');
        $r->assertOk();

        $h = $r->json('data.headline');
        $this->assertSame(1, $h['enrolled_count']);
        $this->assertSame(1, $h['completed_count']);
        $this->assertEquals(90.0, $h['avg_score_percent']);
        $this->assertSame(0, $h['certificates_earned']);
    }

    public function test_role_authorization(): void
    {
        $student = $this->makeUser();
        $student->assignRole('student');

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/trainer/dashboard')->assertForbidden();
        $this->getJson('/api/v1/corporate/dashboard')->assertForbidden();
    }

    public function test_corporate_requires_organization(): void
    {
        $u = $this->makeUser(['organization_id' => null]);
        $u->assignRole('corporate_client');

        Sanctum::actingAs($u);
        $r = $this->getJson('/api/v1/corporate/dashboard');
        $r->assertStatus(422);
    }

    public function test_days_filter_narrows_window(): void
    {
        [, $course, $quiz] = $this->seedTrainerCourseQuiz();
        [, , $employees] = $this->seedOrgWithEmployees($course, $quiz);

        Sanctum::actingAs($employees[0]);
        $r = $this->getJson('/api/v1/student/dashboard?days=7');
        $r->assertOk();
        $this->assertSame(7, $r->json('data.window_days'));
    }

    public function test_status_distribution_semantics(): void
    {
        [, $course, $quiz] = $this->seedTrainerCourseQuiz();
        [, $corp] = $this->seedOrgWithEmployees($course, $quiz);

        Sanctum::actingAs($corp);
        cache()->flush();
        $r = $this->getJson('/api/v1/corporate/dashboard');
        $sd = collect($r->json('data.status_distribution'))->keyBy('status');

        // Scenarios: 3 completed (progress=100) + 2 in_progress (progress=50)
        $this->assertSame(0, $sd['not_started']['count']);
        $this->assertSame(2, $sd['in_progress']['count']);
        $this->assertSame(3, $sd['completed']['count']);
    }
}
