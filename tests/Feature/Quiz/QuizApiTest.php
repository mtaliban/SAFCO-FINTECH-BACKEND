<?php

namespace Tests\Feature\Quiz;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 5 & 6 — Quiz & Question Bank API tests.
 *
 * Covers: quiz CRUD, question bank CRUD, question management,
 * attach/detach, publish flow, role guards.
 */
class QuizApiTest extends TestCase
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

    private function makeBank(User $owner, array $extra = []): QuestionBank
    {
        return QuestionBank::create(array_merge([
            'uuid'     => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'name'     => 'Test Bank ' . Str::random(4),
            'slug'     => 'bank-' . Str::random(6),
            'category' => 'excel',
        ], $extra));
    }

    private function makeQuestion(QuestionBank $bank, User $creator, array $extra = []): Question
    {
        return Question::create(array_merge([
            'uuid'             => (string) Str::uuid(),
            'question_bank_id' => $bank->id,
            'created_by'       => $creator->id,
            'type'             => 'multiple_choice',
            'text'             => 'What is 2+2?',
            'options'          => [
                ['id' => 'a', 'label' => '3'],
                ['id' => 'b', 'label' => '4'],
                ['id' => 'c', 'label' => '5'],
            ],
            'correct_answer'   => 'b',
            'points'           => 10,
            'difficulty'       => 'easy',
        ], $extra));
    }

    private function makeQuiz(User $creator, array $extra = []): Quiz
    {
        return Quiz::create(array_merge([
            'uuid'       => (string) Str::uuid(),
            'created_by' => $creator->id,
            'name'       => 'Test Quiz ' . Str::random(4),
            'slug'       => 'quiz-' . Str::random(6),
            'mode'       => 'self_paced',
            'category'   => 'excel',
            'difficulty' => 'easy',
            'status'     => 'draft',
        ], $extra));
    }

    /** Attach question to quiz using pivot columns from migration (override_points, override_time_seconds). */
    private function attachQuestion(Quiz $quiz, Question $question, int $position = 1): void
    {
        $quiz->questions()->attach($question->id, [
            'position'               => $position,
            'override_time_seconds'  => null,
            'override_points'        => null,
        ]);
    }

    // ── Question Banks ────────────────────────────────────────────────────────

    public function test_list_question_banks_requires_auth(): void
    {
        $this->getJson('/api/v1/question-banks')->assertStatus(401);
    }

    public function test_student_cannot_list_question_banks(): void
    {
        $student = $this->makeUser('student');
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/question-banks')->assertStatus(403);
    }

    public function test_trainer_can_list_question_banks(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);
        $this->makeBank($trainer);

        // The endpoint returns a Laravel paginator; success wrapper adds 'data'
        $res = $this->getJson('/api/v1/question-banks');
        $res->assertOk()->assertJsonStructure(['data']);
    }

    public function test_trainer_can_create_question_bank(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);

        $res = $this->postJson('/api/v1/question-banks', [
            'name'     => 'Excel Formulas',
            'category' => 'excel',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('question_banks', ['name' => 'Excel Formulas']);
    }

    public function test_trainer_can_view_their_bank(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);
        $bank = $this->makeBank($trainer);

        $this->getJson("/api/v1/question-banks/{$bank->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $bank->uuid);
    }

    // ── Questions in Bank ─────────────────────────────────────────────────────

    public function test_trainer_can_add_question_to_bank(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);
        $bank = $this->makeBank($trainer);

        $res = $this->postJson("/api/v1/question-banks/{$bank->uuid}/questions", [
            'type'           => 'multiple_choice',
            'text'           => 'What does VLOOKUP return?',
            'options'        => [
                ['id' => 'a', 'label' => 'A value from a column'],
                ['id' => 'b', 'label' => 'A count of cells'],
            ],
            'correct_answer' => 'a',
            'points'         => 10,
            'difficulty'     => 'medium',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.type', 'multiple_choice');
    }

    public function test_trainer_can_list_questions_in_bank(): void
    {
        $trainer = $this->makeUser();
        $bank    = $this->makeBank($trainer);
        $this->makeQuestion($bank, $trainer);

        Sanctum::actingAs($trainer);

        $this->getJson("/api/v1/question-banks/{$bank->uuid}/questions")
            ->assertOk()
            ->assertJsonStructure(['data' => ['data', 'meta']]);
    }

    public function test_trainer_can_update_question(): void
    {
        $trainer  = $this->makeUser();
        $bank     = $this->makeBank($trainer);
        $question = $this->makeQuestion($bank, $trainer);

        Sanctum::actingAs($trainer);

        // updateQuestion uses StoreQuestionRequest — type + text are required
        $this->patchJson("/api/v1/questions/{$question->uuid}", [
            'type' => 'multiple_choice',
            'text' => 'Updated question text',
            'options' => [
                ['id' => 'a', 'label' => '3'],
                ['id' => 'b', 'label' => '4'],
            ],
            'correct_answer' => 'b',
        ])->assertOk()
          ->assertJsonPath('data.text', 'Updated question text');
    }

    public function test_trainer_can_delete_question(): void
    {
        $trainer  = $this->makeUser();
        $bank     = $this->makeBank($trainer);
        $question = $this->makeQuestion($bank, $trainer);

        Sanctum::actingAs($trainer);

        $this->deleteJson("/api/v1/questions/{$question->uuid}")->assertOk();
        $this->assertSoftDeleted('questions', ['id' => $question->id]);
    }

    // ── Quiz CRUD ─────────────────────────────────────────────────────────────

    public function test_quiz_index_requires_auth(): void
    {
        $this->getJson('/api/v1/quizzes')->assertStatus(401);
    }

    public function test_trainer_can_create_quiz(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);

        $res = $this->postJson('/api/v1/quizzes', [
            'name'             => 'Excel Basics Quiz',
            'mode'             => 'self_paced',
            'category'         => 'excel',
            'difficulty'       => 'beginner',
            'duration_minutes' => 30,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.name', 'Excel Basics Quiz')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('quizzes', ['name' => 'Excel Basics Quiz']);
    }

    public function test_student_cannot_access_quiz_crud_endpoint(): void
    {
        // /api/v1/quizzes is gated to trainer|system_admin
        $student = $this->makeUser('student');
        Sanctum::actingAs($student);

        $this->getJson('/api/v1/quizzes')->assertStatus(403);
    }

    public function test_trainer_can_update_quiz(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);
        $quiz = $this->makeQuiz($trainer);

        $this->patchJson("/api/v1/quizzes/{$quiz->uuid}", [
            'name' => 'Updated Quiz Name',
        ])->assertOk()
          ->assertJsonPath('data.name', 'Updated Quiz Name');
    }

    public function test_trainer_can_delete_quiz(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);
        $quiz = $this->makeQuiz($trainer);

        $this->deleteJson("/api/v1/quizzes/{$quiz->uuid}")->assertOk();
        $this->assertSoftDeleted('quizzes', ['id' => $quiz->id]);
    }

    public function test_quiz_show_returns_details(): void
    {
        $trainer = $this->makeUser();
        Sanctum::actingAs($trainer);
        $quiz = $this->makeQuiz($trainer);

        // QuizResource uses 'id' (mapped from uuid)
        $this->getJson("/api/v1/quizzes/{$quiz->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $quiz->uuid);
    }

    // ── Attach / Detach Questions ─────────────────────────────────────────────

    public function test_trainer_can_attach_questions_to_quiz(): void
    {
        $trainer  = $this->makeUser();
        $bank     = $this->makeBank($trainer);
        $question = $this->makeQuestion($bank, $trainer);
        $quiz     = $this->makeQuiz($trainer);

        Sanctum::actingAs($trainer);

        // The endpoint uses 'question_uuids' (not 'question_ids')
        $this->postJson("/api/v1/quizzes/{$quiz->uuid}/attach-questions", [
            'question_uuids' => [$question->uuid],
        ])->assertOk();

        $this->assertDatabaseHas('quiz_questions', [
            'quiz_id'     => $quiz->id,
            'question_id' => $question->id,
        ]);
    }

    public function test_trainer_can_detach_questions_from_quiz(): void
    {
        $trainer  = $this->makeUser();
        $bank     = $this->makeBank($trainer);
        $question = $this->makeQuestion($bank, $trainer);
        $quiz     = $this->makeQuiz($trainer);

        $this->attachQuestion($quiz, $question);

        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/quizzes/{$quiz->uuid}/detach-questions", [
            'question_uuids' => [$question->uuid],
        ])->assertOk();

        $this->assertDatabaseMissing('quiz_questions', [
            'quiz_id'     => $quiz->id,
            'question_id' => $question->id,
        ]);
    }

    // ── Publish ───────────────────────────────────────────────────────────────

    public function test_trainer_can_publish_quiz_with_questions(): void
    {
        $trainer  = $this->makeUser();
        $bank     = $this->makeBank($trainer);
        $question = $this->makeQuestion($bank, $trainer);
        $quiz     = $this->makeQuiz($trainer);

        $this->attachQuestion($quiz, $question);

        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/quizzes/{$quiz->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_cannot_publish_quiz_without_questions(): void
    {
        $trainer = $this->makeUser();
        $quiz    = $this->makeQuiz($trainer);

        Sanctum::actingAs($trainer);

        $this->postJson("/api/v1/quizzes/{$quiz->uuid}/publish")
            ->assertStatus(422);
    }

    // ── Trainer my-quizzes ────────────────────────────────────────────────────

    public function test_trainer_can_list_their_own_quizzes(): void
    {
        $trainer = $this->makeUser();
        $this->makeQuiz($trainer, ['status' => 'draft']);

        Sanctum::actingAs($trainer);

        // Trainers use /trainer/my-quizzes for their own quiz list
        $res = $this->getJson('/api/v1/trainer/my-quizzes');
        $res->assertOk();
    }

    // ── Student available quizzes ─────────────────────────────────────────────

    public function test_student_available_quizzes_returns_published(): void
    {
        $trainer = $this->makeUser();
        $student = $this->makeUser('student');

        $this->makeQuiz($trainer, ['status' => 'published']);

        Sanctum::actingAs($student);

        // Students use /student/available-quizzes (not /quizzes which is trainer-only)
        $this->getJson('/api/v1/student/available-quizzes')->assertOk();
    }

    // ── Duplicate ─────────────────────────────────────────────────────────────

    public function test_trainer_can_duplicate_quiz(): void
    {
        $trainer  = $this->makeUser();
        $bank     = $this->makeBank($trainer);
        $question = $this->makeQuestion($bank, $trainer);
        $quiz     = $this->makeQuiz($trainer);

        $this->attachQuestion($quiz, $question);

        Sanctum::actingAs($trainer);

        $res = $this->postJson("/api/v1/quizzes/{$quiz->uuid}/duplicate");
        $res->assertStatus(201);

        // New UUID differs from original
        $this->assertNotSame($quiz->uuid, $res->json('data.id'));
    }
}
