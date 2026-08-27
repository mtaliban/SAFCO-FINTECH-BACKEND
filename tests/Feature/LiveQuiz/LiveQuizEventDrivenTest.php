<?php

namespace Tests\Feature\LiveQuiz;

use App\Events\Quiz\AnswerSubmitted;
use App\Events\Quiz\LeaderboardUpdated;
use App\Events\Quiz\ParticipantJoined;
use App\Events\Quiz\QuestionEnded;
use App\Events\Quiz\QuestionStarted;
use App\Events\Quiz\QuizSessionCompleted;
use App\Events\Quiz\QuizSessionCreated;
use App\Models\EventOutbox;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizSession;
use App\Models\QuizSessionParticipant;
use App\Models\User;
use App\Services\EventBus\MqttPublisher;
use App\Services\Quiz\LiveQuizSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 7 — Live Quiz Engine: event-driven + real-time tests.
 *
 * Verifies:
 *   1. Every state change writes to EventOutbox (Transactional Outbox pattern)
 *   2. MQTT publishRaw is called with correct topic per event
 *   3. Redis counter (Cache::increment) is used for answer position tracking
 *   4. Full session lifecycle: create → join → start Q → answer → end Q → leaderboard → complete
 *   5. HTTP endpoints: host start, student join/answer, leaderboard, participants
 *   6. Duplicate answers are rejected
 *   7. Role guards: only trainer/admin may host
 */
class LiveQuizEventDrivenTest extends TestCase
{
    use RefreshDatabase;

    private MqttPublisher $mockMqtt;
    private array $mqttCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student', 'corporate_client'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        // Capture MQTT publishRaw calls without hitting the real broker
        $this->mqttCalls = [];
        $this->mockMqtt = $this->createMock(MqttPublisher::class);
        $this->mockMqtt->method('publishRaw')
            ->willReturnCallback(function (string $topic, array $payload) {
                $this->mqttCalls[] = ['topic' => $topic, 'payload' => $payload];
                return true;
            });

        $this->app->instance(MqttPublisher::class, $this->mockMqtt);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeUser(string $role = 'trainer'): User
    {
        $u = User::create([
            'uuid'              => (string) Str::uuid(),
            'email'             => 'u' . Str::random(6) . '@test.io',
            'password'          => bcrypt('secret'),
            'email_verified_at' => now(),
            'status'            => 'active',
        ]);
        $u->assignRole($role);
        return $u;
    }

    private function makePublishedQuizWithQuestion(User $trainer): array
    {
        $bank = QuestionBank::create([
            'uuid'     => (string) Str::uuid(),
            'owner_id' => $trainer->id,
            'name'     => 'Test Bank',
            'slug'     => 'bank-' . Str::random(5),
            'category' => 'excel',
        ]);

        $question = Question::create([
            'uuid'             => (string) Str::uuid(),
            'question_bank_id' => $bank->id,
            'created_by'       => $trainer->id,
            'type'             => 'multiple_choice',
            'text'             => 'What is Excel?',
            'options'          => [
                ['id' => 'a', 'label' => 'A spreadsheet'],
                ['id' => 'b', 'label' => 'A database'],
            ],
            'correct_answer'   => 'a',
            'points'           => 100,
            'time_limit_seconds' => 30,
            'difficulty'       => 'easy',
        ]);

        $quiz = Quiz::create([
            'uuid'            => (string) Str::uuid(),
            'created_by'      => $trainer->id,
            'name'            => 'Live Quiz ' . Str::random(4),
            'slug'            => 'lq-' . Str::random(6),
            'mode'            => 'self_paced',
            'category'        => 'excel',
            'difficulty'      => 'beginner',
            'status'          => 'published',
            'show_leaderboard' => true,
        ]);

        $quiz->questions()->attach($question->id, [
            'position'              => 1,
            'override_time_seconds' => 30,
            'override_points'       => null,
        ]);

        return [$quiz, $question];
    }

    private function svc(): LiveQuizSessionService
    {
        return $this->app->make(LiveQuizSessionService::class);
    }

    // ── 1. Session creation writes to EventOutbox ──────────────────────────────

    public function test_create_session_writes_quiz_session_created_to_outbox(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);

        $this->svc()->createSession($trainer, $quiz);

        $this->assertDatabaseHas('event_outbox', [
            'event_name'     => QuizSessionCreated::eventName(),
            'aggregate_type' => QuizSession::class,
        ]);
    }

    public function test_create_session_broadcasts_mqtt_on_created_topic(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);

        $session = $this->svc()->createSession($trainer, $quiz);

        $topics = array_column($this->mqttCalls, 'topic');
        $this->assertTrue(
            in_array("safco/lms/quiz/session/{$session->pin}/created", $topics, true),
            'Expected MQTT broadcast on session/created topic'
        );
    }

    public function test_unpublished_quiz_cannot_be_hosted(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $quiz->update(['status' => 'draft']);

        $this->expectException(\DomainException::class);
        $this->svc()->createSession($trainer, $quiz);
    }

    public function test_quiz_with_no_questions_cannot_be_hosted(): void
    {
        $trainer = $this->makeUser();
        $quiz = Quiz::create([
            'uuid'       => (string) Str::uuid(),
            'created_by' => $trainer->id,
            'name'       => 'Empty Quiz',
            'slug'       => 'empty-' . Str::random(4),
            'mode'       => 'self_paced',
            'category'   => 'excel',
            'difficulty' => 'beginner',
            'status'     => 'published',
        ]);

        $this->expectException(\DomainException::class);
        $this->svc()->createSession($trainer, $quiz);
    }

    // ── 2. Student join writes to outbox + MQTT ────────────────────────────────

    public function test_join_writes_participant_joined_to_outbox(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, [
            'nickname'   => 'TestPlayer',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertDatabaseHas('event_outbox', [
            'event_name' => ParticipantJoined::eventName(),
        ]);
    }

    public function test_join_broadcasts_mqtt_participant_joined(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'Player1', 'ip_address' => '127.0.0.1']);

        $topics = array_column($this->mqttCalls, 'topic');
        $this->assertTrue(
            in_array("safco/lms/quiz/session/{$session->pin}/participant_joined", $topics, true)
        );
    }

    public function test_duplicate_nickname_in_same_session_rejected(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'Player1', 'ip_address' => '127.0.0.1']);

        $this->expectException(\DomainException::class);
        $this->svc()->joinByPin($session->pin, ['nickname' => 'Player1', 'ip_address' => '127.0.0.2']);
    }

    public function test_participant_count_increments_on_join(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'A', 'ip_address' => '1.1.1.1']);
        $this->svc()->joinByPin($session->pin, ['nickname' => 'B', 'ip_address' => '1.1.1.2']);

        $this->assertSame(2, $session->fresh()->participant_count);
    }

    // ── 3. Question start: outbox + MQTT + Redis counter reset ────────────────

    public function test_start_question_writes_question_started_to_outbox(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->startNextQuestion($session->fresh());

        $this->assertDatabaseHas('event_outbox', [
            'event_name' => QuestionStarted::eventName(),
        ]);
    }

    public function test_start_question_broadcasts_question_started_on_mqtt(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->startNextQuestion($session->fresh());

        $topics = array_column($this->mqttCalls, 'topic');
        $this->assertTrue(
            in_array("safco/lms/quiz/session/{$session->pin}/question_started", $topics, true)
        );
    }

    public function test_start_question_clears_redis_answer_counter(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        // Pre-populate a stale counter
        Cache::put("session:{$session->pin}:answers:{$question->id}", 99);

        $this->svc()->startNextQuestion($session->fresh());

        $val = Cache::get("session:{$session->pin}:answers:{$question->id}");
        $this->assertNull($val, 'Redis answer counter must be cleared when question starts');
    }

    public function test_question_payload_omits_correct_answer(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $payload = $this->svc()->startNextQuestion($session->fresh());

        // Students must not see the correct answer in the broadcast
        $this->assertArrayNotHasKey('correct_answer', $payload);
        foreach ($payload['options'] as $opt) {
            $this->assertArrayNotHasKey('is_correct', $opt);
        }
    }

    // ── 4. Answer submission: Redis counter + outbox + MQTT to host ───────────

    public function test_answer_increments_redis_counter(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'P1', 'ip_address' => '1.1.1.1']);
        $participant = $session->participants()->first();

        $session->update([
            'status'                        => 'question_active',
            'current_question_index'        => 0,
            'current_question_id'           => $question->id,
            'current_question_started_at'   => now()->subSeconds(2),
            'current_question_ends_at'      => now()->addSeconds(28),
        ]);

        Cache::forget("session:{$session->pin}:answers:{$question->id}");

        $this->svc()->submitAnswer($session->fresh(), $participant, 'a');

        $count = Cache::get("session:{$session->pin}:answers:{$question->id}");
        $this->assertSame(1, (int) $count, 'Redis counter must be 1 after first answer');
    }

    public function test_answer_writes_answer_submitted_to_outbox(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'P1', 'ip_address' => '1.1.1.1']);
        $participant = $session->participants()->first();

        $session->update([
            'status'                      => 'question_active',
            'current_question_index'      => 0,
            'current_question_id'         => $question->id,
            'current_question_started_at' => now()->subSeconds(2),
            'current_question_ends_at'    => now()->addSeconds(28),
        ]);

        $this->svc()->submitAnswer($session->fresh(), $participant, 'a');

        $this->assertDatabaseHas('event_outbox', [
            'event_name' => AnswerSubmitted::eventName(),
        ]);
    }

    public function test_answer_broadcasts_to_host_mqtt_topic(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'P1', 'ip_address' => '1.1.1.1']);
        $participant = $session->participants()->first();

        $session->update([
            'status'                      => 'question_active',
            'current_question_index'      => 0,
            'current_question_id'         => $question->id,
            'current_question_started_at' => now()->subSeconds(2),
            'current_question_ends_at'    => now()->addSeconds(28),
        ]);

        $this->svc()->submitAnswer($session->fresh(), $participant, 'a');

        $topics = array_column($this->mqttCalls, 'topic');
        $this->assertTrue(
            in_array("safco/lms/quiz/session/{$session->pin}/host/answers", $topics, true),
            'Answer must broadcast to host-only MQTT topic'
        );
    }

    public function test_duplicate_answer_rejected(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'P1', 'ip_address' => '1.1.1.1']);
        $participant = $session->participants()->first();

        $session->update([
            'status'                      => 'question_active',
            'current_question_index'      => 0,
            'current_question_id'         => $question->id,
            'current_question_started_at' => now()->subSeconds(2),
            'current_question_ends_at'    => now()->addSeconds(28),
        ]);

        $this->svc()->submitAnswer($session->fresh(), $participant, 'a');

        $this->expectException(\DomainException::class);
        $this->svc()->submitAnswer($session->fresh(), $participant, 'b');
    }

    public function test_correct_answer_scores_points(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'P1', 'ip_address' => '1.1.1.1']);
        $participant = $session->participants()->first();

        $session->update([
            'status'                      => 'question_active',
            'current_question_index'      => 0,
            'current_question_id'         => $question->id,
            'current_question_started_at' => now()->subSeconds(2),
            'current_question_ends_at'    => now()->addSeconds(28),
        ]);

        $result = $this->svc()->submitAnswer($session->fresh(), $participant, 'a'); // correct

        $this->assertTrue($result['is_correct']);
        $this->assertGreaterThan(0, $result['points_earned']);
    }

    public function test_wrong_answer_scores_zero(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'P1', 'ip_address' => '1.1.1.1']);
        $participant = $session->participants()->first();

        $session->update([
            'status'                      => 'question_active',
            'current_question_index'      => 0,
            'current_question_id'         => $question->id,
            'current_question_started_at' => now()->subSeconds(2),
            'current_question_ends_at'    => now()->addSeconds(28),
        ]);

        $result = $this->svc()->submitAnswer($session->fresh(), $participant, 'b'); // wrong

        $this->assertFalse($result['is_correct']);
        $this->assertSame(0, $result['points_earned']);
    }

    // ── 5. End question: leaderboard outbox + MQTT ────────────────────────────

    public function test_end_question_writes_question_ended_to_outbox(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $session->update([
            'status'                      => 'question_active',
            'current_question_index'      => 0,
            'current_question_id'         => $question->id,
            'current_question_started_at' => now()->subSeconds(30),
            'current_question_ends_at'    => now()->subSeconds(1),
        ]);

        $this->svc()->endCurrentQuestion($session->fresh());

        $this->assertDatabaseHas('event_outbox', ['event_name' => QuestionEnded::eventName()]);
    }

    public function test_end_question_broadcasts_leaderboard_on_mqtt(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $session->update([
            'status'                      => 'question_active',
            'current_question_index'      => 0,
            'current_question_id'         => $question->id,
            'current_question_started_at' => now()->subSeconds(30),
            'current_question_ends_at'    => now()->subSeconds(1),
        ]);

        $this->svc()->endCurrentQuestion($session->fresh());

        $topics = array_column($this->mqttCalls, 'topic');
        $this->assertTrue(
            in_array("safco/lms/quiz/session/{$session->pin}/question_ended", $topics, true)
        );
        $this->assertTrue(
            in_array("safco/lms/quiz/session/{$session->pin}/leaderboard", $topics, true),
            'Leaderboard must be broadcast after question ends'
        );
    }

    // ── 6. Complete session ────────────────────────────────────────────────────

    public function test_complete_writes_quiz_session_completed_to_outbox(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->complete($session->fresh());

        $this->assertDatabaseHas('event_outbox', [
            'event_name' => QuizSessionCompleted::eventName(),
        ]);
    }

    public function test_complete_sets_session_status_to_completed(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->complete($session->fresh());

        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_complete_broadcasts_on_mqtt_completed_topic(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->complete($session->fresh());

        $topics = array_column($this->mqttCalls, 'topic');
        $this->assertTrue(
            in_array("safco/lms/quiz/session/{$session->pin}/completed", $topics, true)
        );
    }

    // ── 7. HTTP Endpoints ──────────────────────────────────────────────────────

    public function test_host_start_requires_trainer_role(): void
    {
        $student = $this->makeUser('student');
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/quizzes/{$quiz->uuid}/host")->assertStatus(403);
    }

    public function test_trainer_can_host_quiz_via_http(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);

        Sanctum::actingAs($trainer);
        $res = $this->postJson("/api/v1/quizzes/{$quiz->uuid}/host");

        // QuizSessionResource maps uuid → 'id'
        $res->assertStatus(201)
            ->assertJsonStructure(['data' => ['pin', 'status', 'id']]);
    }

    public function test_student_can_join_session_via_http(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $res = $this->postJson('/api/v1/play/join', [
            'pin'      => $session->pin,
            'nickname' => 'Hamisi',
        ]);

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => ['participant']]);
    }

    public function test_student_can_get_session_state_via_http(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->getJson("/api/v1/play/session/{$session->pin}")
            ->assertOk()
            ->assertJsonPath('data.pin', $session->pin);
    }

    public function test_host_can_view_leaderboard_via_http(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        Sanctum::actingAs($trainer);
        // getLeaderboard returns a flat array directly in 'data'
        $this->getJson("/api/v1/sessions/{$session->uuid}/leaderboard")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_host_can_view_participants_via_http(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);
        $session = $this->svc()->createSession($trainer, $quiz);

        $this->svc()->joinByPin($session->pin, ['nickname' => 'A', 'ip_address' => '1.1.1.1']);

        Sanctum::actingAs($trainer);
        $this->getJson("/api/v1/sessions/{$session->uuid}/participants")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    // ── 8. Redis counter isolation between sessions ────────────────────────────

    public function test_redis_counters_are_isolated_per_session_pin(): void
    {
        $trainer = $this->makeUser();
        [$quiz, $question] = $this->makePublishedQuizWithQuestion($trainer);

        $session1 = $this->svc()->createSession($trainer, $quiz);
        $session2 = $this->svc()->createSession($trainer, $quiz);

        Cache::put("session:{$session1->pin}:answers:{$question->id}", 5);
        Cache::put("session:{$session2->pin}:answers:{$question->id}", 99);

        $this->assertSame(5,  (int) Cache::get("session:{$session1->pin}:answers:{$question->id}"));
        $this->assertSame(99, (int) Cache::get("session:{$session2->pin}:answers:{$question->id}"));
    }

    // ── 9. Outbox contains routing keys for broker dispatch ───────────────────

    public function test_outbox_rows_contain_routing_key_and_broker_info(): void
    {
        $trainer = $this->makeUser();
        [$quiz] = $this->makePublishedQuizWithQuestion($trainer);

        $this->svc()->createSession($trainer, $quiz);

        $row = EventOutbox::where('event_name', QuizSessionCreated::eventName())->first();
        $this->assertNotNull($row);
        $this->assertNotEmpty($row->routing_key);
        $this->assertNotEmpty($row->broker);
        $this->assertSame('pending', $row->status);
    }
}
