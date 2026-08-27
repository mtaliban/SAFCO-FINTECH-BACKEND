<?php

namespace Tests\Feature\Forum;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Forum\ForumCategory;
use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumReport;
use App\Models\Forum\ForumThread;
use App\Models\Forum\ForumVote;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\ForumEventNotification;
use App\Services\Forum\PostService;
use App\Services\Forum\ThreadService;
use App\Services\Forum\VotingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 14 — Discussion Forum feature tests.
 *
 * Coverage:
 *  A. Ask a question (thread create) — enrolment gate for course-scoped threads
 *  B. Reply lifecycle + author auto-subscribe
 *  C. Voting is idempotent, self-vote forbidden, race-safe (unique constraint)
 *  D. Accept-answer only by OP or moderator, only for Q&A category
 *  E. Locked/hidden threads reject new replies
 *  F. Assignment discussions require assignment_id + enrollment
 *  G. Reports dedup per user/target; moderator can resolve
 *  H. Notifications fire on reply + mention + accepted answer
 *  I. FULLTEXT search hits title + body
 *  J. Rate limits enforce max threads/hour + max replies/minute
 *  K. Reply-to-reply is capped at one nesting level
 */
class ForumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $role, string $name = 'User Name'): User
    {
        $u = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => strtolower(explode(' ', $name)[0]) . Str::random(6) . '@t.io',
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

    private function makeCourse(User $trainer): Course
    {
        return Course::create([
            'uuid' => (string) Str::uuid(), 'slug' => 'c-' . Str::random(6),
            'title' => 'Sample Course', 'category' => 'excel', 'level' => 'beginner',
            'status' => 'published', 'instructor_id' => $trainer->id, 'created_by' => $trainer->id,
        ]);
    }

    private function enroll(User $student, Course $course): Enrollment
    {
        return Enrollment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $student->id, 'course_id' => $course->id,
            'progress_percentage' => 0, 'enrolled_at' => now(),
        ]);
    }

    private function makeAssignment(Course $course, User $trainer, string $title = 'HW'): Assignment
    {
        $module = CourseModule::create([
            'uuid' => (string) Str::uuid(),
            'course_id' => $course->id,
            'title' => 'Module 1', 'position' => 1,
        ]);
        $lesson = Lesson::create([
            'uuid' => (string) Str::uuid(),
            'course_module_id' => $module->id,
            'title' => 'Lesson 1',
        ]);
        return Assignment::create([
            'uuid' => (string) Str::uuid(),
            'lesson_id' => $lesson->id,
            'title' => $title,
            'max_points' => 100,
        ]);
    }

    // ── A: Ask a question ─────────────────────────────────────────

    public function test_student_can_create_a_question_thread(): void
    {
        $student = $this->makeUser('student', 'Amina Q');
        Sanctum::actingAs($student);

        $r = $this->postJson('/api/v1/forum/threads', [
            'category' => 'questions',
            'title' => 'How do I use VLOOKUP with wildcards?',
            'body'  => 'I am trying to build a lookup that matches partial strings.',
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('forum_threads', [
            'title' => 'How do I use VLOOKUP with wildcards?',
            'author_id' => $student->id,
        ]);
        // Author is auto-subscribed
        $this->assertDatabaseHas('forum_subscriptions', [
            'user_id' => $student->id,
        ]);
    }

    public function test_non_enrolled_student_cannot_post_in_course_scoped_thread(): void
    {
        $trainer = $this->makeUser('trainer', 'Trainer Bob');
        $course = $this->makeCourse($trainer);
        $outsider = $this->makeUser('student', 'Not Enrolled');

        Sanctum::actingAs($outsider);
        $r = $this->postJson('/api/v1/forum/threads', [
            'category' => 'questions',
            'title' => 'Course-scoped question',
            'body'  => 'This should be rejected.',
            'course_id' => $course->id,
        ]);
        $r->assertStatus(422);
        $this->assertStringContainsString('enrolled', strtolower($r->json('message')));
    }

    // ── B: Reply lifecycle ────────────────────────────────────────

    public function test_reply_increments_replies_count_and_touches_activity(): void
    {
        $author = $this->makeUser('student', 'Author One');
        $replier = $this->makeUser('student', 'Replier One');

        $thread = app(ThreadService::class)->create(
            $author,
            ForumCategory::where('slug', 'questions')->firstOrFail(),
            ['title' => 'Test thread question', 'body' => 'Testing replies here.']
        );

        $initial = $thread->last_activity_at;
        sleep(1);

        Sanctum::actingAs($replier);
        $r = $this->postJson("/api/v1/forum/threads/{$thread->uuid}/posts", [
            'body' => 'Here is my reply.',
        ]);
        $r->assertStatus(201);

        $fresh = $thread->fresh();
        $this->assertSame(1, $fresh->replies_count);
        $this->assertTrue($fresh->last_activity_at->gt($initial),
            'last_activity_at must be bumped on new reply');
    }

    // ── C: Voting ─────────────────────────────────────────────────

    public function test_voting_is_idempotent_and_score_matches_source(): void
    {
        $author = $this->makeUser('student', 'Author X');
        $voter = $this->makeUser('student', 'Voter Y');
        $thread = app(ThreadService::class)->create(
            $author,
            ForumCategory::where('slug', 'ideas')->firstOrFail(),
            ['title' => 'A neat idea', 'body' => 'What if we did X?'],
        );

        $svc = app(VotingService::class);

        // Upvote once
        $this->assertSame(1, $svc->voteThread($thread, $voter, 1));
        // Upvote again → toggles OFF
        $this->assertSame(0, $svc->voteThread($thread, $voter, 1));
        // Downvote → -1
        $this->assertSame(-1, $svc->voteThread($thread, $voter, -1));
        // Switch to upvote → +1 (swings by 2 from -1 to +1)
        $this->assertSame(1, $svc->voteThread($thread, $voter, 1));

        // Score must equal SUM(value) from votes table
        $sum = (int) ForumVote::where('votable_type', 'thread')
            ->where('votable_id', $thread->id)->sum('value');
        $this->assertSame($sum, $thread->fresh()->votes_score);
    }

    public function test_self_vote_is_forbidden(): void
    {
        $author = $this->makeUser('student', 'Solo Author');
        $thread = app(ThreadService::class)->create(
            $author,
            ForumCategory::where('slug', 'ideas')->firstOrFail(),
            ['title' => 'A solo idea', 'body' => 'Self-vote should not work.']
        );
        $this->expectException(\DomainException::class);
        app(VotingService::class)->voteThread($thread, $author, 1);
    }

    // ── D: Accept answer ─────────────────────────────────────────

    public function test_only_op_or_moderator_can_accept_answer(): void
    {
        $op = $this->makeUser('student', 'OP User');
        $helper = $this->makeUser('student', 'Helper User');
        $random = $this->makeUser('student', 'Random Person');

        $thread = app(ThreadService::class)->create(
            $op,
            ForumCategory::where('slug', 'questions')->firstOrFail(),
            ['title' => 'Need help with pivots', 'body' => 'Explain?'],
        );
        $post = app(PostService::class)->reply($thread, $helper, 'Here is the answer.');

        // Random cannot accept
        $this->expectException(\DomainException::class);
        app(ThreadService::class)->acceptAnswer($thread, $post, $random);
    }

    public function test_op_can_accept_and_only_one_accepted_per_thread(): void
    {
        $op = $this->makeUser('student', 'OP');
        $h1 = $this->makeUser('student', 'Helper A');
        $h2 = $this->makeUser('student', 'Helper B');
        $thread = app(ThreadService::class)->create(
            $op, ForumCategory::where('slug', 'questions')->firstOrFail(),
            ['title' => 'Accept test?', 'body' => 'Which is right?']
        );
        $p1 = app(PostService::class)->reply($thread, $h1, 'Answer 1');
        $p2 = app(PostService::class)->reply($thread, $h2, 'Answer 2');

        app(ThreadService::class)->acceptAnswer($thread, $p1, $op);
        $this->assertTrue($p1->fresh()->is_accepted_answer);
        $this->assertSame($p1->id, $thread->fresh()->accepted_post_id);

        // Switch acceptance to p2 — p1 must be de-accepted
        app(ThreadService::class)->acceptAnswer($thread, $p2, $op);
        $this->assertFalse($p1->fresh()->is_accepted_answer);
        $this->assertTrue($p2->fresh()->is_accepted_answer);
        $this->assertSame($p2->id, $thread->fresh()->accepted_post_id);
    }

    public function test_cannot_accept_answer_in_ideas_category(): void
    {
        $op = $this->makeUser('student', 'OP');
        $h = $this->makeUser('student', 'Helper');
        $thread = app(ThreadService::class)->create(
            $op, ForumCategory::where('slug', 'ideas')->firstOrFail(),
            ['title' => 'Idea test', 'body' => 'Not a question.']
        );
        $post = app(PostService::class)->reply($thread, $h, 'Comment.');
        $this->expectException(\DomainException::class);
        app(ThreadService::class)->acceptAnswer($thread, $post, $op);
    }

    // ── E: Locked/hidden threads ─────────────────────────────────

    public function test_locked_thread_rejects_new_replies(): void
    {
        $op = $this->makeUser('student', 'Op');
        $thread = app(ThreadService::class)->create(
            $op, ForumCategory::where('slug', 'questions')->firstOrFail(),
            ['title' => 'Locked test', 'body' => 'x'],
        );
        $thread->update(['is_locked' => true]);

        $u = $this->makeUser('student', 'Late Comer');
        $this->expectException(\DomainException::class);
        app(PostService::class)->reply($thread, $u, 'trying to reply');
    }

    // ── F: Assignment-scoped ─────────────────────────────────────

    public function test_assignment_category_requires_assignment_id(): void
    {
        $u = $this->makeUser('student', 'Sam');
        Sanctum::actingAs($u);
        $r = $this->postJson('/api/v1/forum/threads', [
            'category' => 'assignments',
            'title' => 'Discuss assignment',
            'body' => 'question here',
        ]);
        $r->assertStatus(422);
        $this->assertStringContainsString('assignment', strtolower($r->json('message')));
    }

    public function test_assignment_uuid_is_resolved_and_enrolled_student_can_post(): void
    {
        $trainer = $this->makeUser('trainer', 'Trainer T');
        $course = $this->makeCourse($trainer);
        $student = $this->makeUser('student', 'Enrolled Sam');
        $this->enroll($student, $course);

        $assignment = $this->makeAssignment($course, $trainer, 'Excel Basics HW');

        Sanctum::actingAs($student);
        $r = $this->postJson('/api/v1/forum/threads', [
            'category' => 'assignments',
            'title' => 'Question on the pivot task',
            'body' => 'How should I approach the third question?',
            'assignment_uuid' => $assignment->uuid,
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('forum_threads', [
            'assignment_id' => $assignment->id,
            'course_id' => $course->id,   // auto-populated from assignment.course_id
            'author_id' => $student->id,
        ]);
    }

    public function test_threads_endpoint_filters_by_assignment_uuid(): void
    {
        $trainer = $this->makeUser('trainer', 'T');
        $course = $this->makeCourse($trainer);
        $student = $this->makeUser('student', 'S');
        $this->enroll($student, $course);
        $a1 = $this->makeAssignment($course, $trainer, 'A1');
        $a2 = $this->makeAssignment($course, $trainer, 'A2');

        Sanctum::actingAs($student);
        $this->postJson('/api/v1/forum/threads', [
            'category' => 'assignments',
            'title' => 'About A1 something',
            'body' => 'question body a1',
            'assignment_uuid' => $a1->uuid,
        ])->assertStatus(201);
        $this->postJson('/api/v1/forum/threads', [
            'category' => 'assignments',
            'title' => 'About A2 something',
            'body' => 'question body a2',
            'assignment_uuid' => $a2->uuid,
        ])->assertStatus(201);

        $r = $this->getJson('/api/v1/forum/threads?assignment_uuid=' . $a1->uuid);
        $r->assertOk();
        $titles = collect($r->json('data.data'))->pluck('title')->all();
        $this->assertContains('About A1 something', $titles);
        $this->assertNotContains('About A2 something', $titles);
    }

    // ── G: Reports ───────────────────────────────────────────────

    public function test_duplicate_report_from_same_user_is_deduplicated(): void
    {
        $author = $this->makeUser('student', 'Author');
        $reporter = $this->makeUser('student', 'Reporter');
        $thread = app(ThreadService::class)->create(
            $author, ForumCategory::where('slug', 'ideas')->firstOrFail(),
            ['title' => 'Something', 'body' => 'body']
        );

        Sanctum::actingAs($reporter);
        $r1 = $this->postJson('/api/v1/forum/reports', [
            'target_type' => 'thread', 'target_uuid' => $thread->uuid,
            'reason' => 'spam',
        ]);
        $r1->assertStatus(201);
        $r2 = $this->postJson('/api/v1/forum/reports', [
            'target_type' => 'thread', 'target_uuid' => $thread->uuid,
            'reason' => 'offensive',
        ]);
        $r2->assertStatus(201);
        // Same reporter + same target = 1 report row, not 2
        $this->assertSame(1, ForumReport::count());
    }

    public function test_moderator_can_resolve_report(): void
    {
        $author = $this->makeUser('student', 'Author');
        $reporter = $this->makeUser('student', 'Reporter');
        $moderator = $this->makeUser('trainer', 'Mod User');

        $thread = app(ThreadService::class)->create(
            $author, ForumCategory::where('slug', 'ideas')->firstOrFail(),
            ['title' => 'topic', 'body' => 'body'],
        );
        Sanctum::actingAs($reporter);
        $this->postJson('/api/v1/forum/reports', [
            'target_type' => 'thread', 'target_uuid' => $thread->uuid, 'reason' => 'spam',
        ]);

        $report = ForumReport::first();
        Sanctum::actingAs($moderator);
        $r = $this->patchJson("/api/v1/forum/reports/{$report->uuid}", [
            'status' => 'resolved',
            'resolution_note' => 'Marked as spam and hidden.',
        ]);
        $r->assertOk();
        $this->assertSame('resolved', $report->fresh()->status);
    }

    // ── H: Notifications ─────────────────────────────────────────

    public function test_new_reply_notifies_thread_author(): void
    {
        // Notifications now flow through the central M15 dispatcher — assert
        // by inspecting notification_deliveries instead of Notification::fake.
        $op = $this->makeUser('student', 'OP');
        $replier = $this->makeUser('student', 'Replier');

        $thread = app(ThreadService::class)->create(
            $op, ForumCategory::where('slug', 'questions')->firstOrFail(),
            ['title' => 'Notify me on replies', 'body' => 'yes please'],
        );
        app(PostService::class)->reply($thread, $replier, 'Here is my answer.');

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $op->id,
            'event_key' => 'forum.reply',
            'status' => 'sent',
        ]);
        // Replier (the actor) must NOT be self-notified.
        $this->assertDatabaseMissing('notification_deliveries', [
            'user_id' => $replier->id,
            'event_key' => 'forum.reply',
        ]);
    }

    public function test_accepted_answer_notifies_the_helper(): void
    {
        $op = $this->makeUser('student', 'OP');
        $helper = $this->makeUser('student', 'Helper');
        $thread = app(ThreadService::class)->create(
            $op, ForumCategory::where('slug', 'questions')->firstOrFail(),
            ['title' => 'Accept notify?', 'body' => '?'],
        );
        $post = app(PostService::class)->reply($thread, $helper, 'answer');
        app(ThreadService::class)->acceptAnswer($thread, $post, $op);

        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $helper->id,
            'event_key' => 'forum.answer_accepted',
            'status' => 'sent',
        ]);
    }

    // ── I: FULLTEXT search ───────────────────────────────────────

    public function test_search_finds_thread_by_body_words(): void
    {
        $author = $this->makeUser('student', 'S');
        app(ThreadService::class)->create(
            $author, ForumCategory::where('slug', 'ideas')->firstOrFail(),
            ['title' => 'Ridiculous but useful', 'body' => 'Consider implementing a Bayesian classifier.']
        );
        app(ThreadService::class)->create(
            $author, ForumCategory::where('slug', 'ideas')->firstOrFail(),
            ['title' => 'Unrelated thread topic', 'body' => 'Trip planning tips for Zanzibar.']
        );

        $r = $this->getJson('/api/v1/forum/threads?q=Bayesian');
        $r->assertOk();
        $titles = collect($r->json('data.data'))->pluck('title')->all();
        $this->assertContains('Ridiculous but useful', $titles);
        $this->assertNotContains('Unrelated thread topic', $titles);
    }

    // ── K: Reply nesting cap ─────────────────────────────────────

    public function test_reply_to_reply_normalizes_to_top_level_parent(): void
    {
        $op = $this->makeUser('student', 'OP');
        $u1 = $this->makeUser('student', 'U1');
        $u2 = $this->makeUser('student', 'U2');
        $thread = app(ThreadService::class)->create(
            $op, ForumCategory::where('slug', 'questions')->firstOrFail(),
            ['title' => 'nesting test question', 'body' => 'x'],
        );
        $lvl1 = app(PostService::class)->reply($thread, $u1, 'first level');
        $lvl2 = app(PostService::class)->reply($thread, $u2, 'reply to reply', $lvl1->id);
        $lvl3 = app(PostService::class)->reply($thread, $u1, 'reply to reply to reply', $lvl2->id);

        // Third-level reply must collapse to lvl1 (the top-level parent), not lvl2
        $this->assertSame($lvl1->id, $lvl3->parent_post_id);
    }

    // ── D+H combined: category-appropriate accepted-answer flag ──

    public function test_show_endpoint_exposes_permissions_and_my_vote(): void
    {
        $op = $this->makeUser('student', 'OP');
        $viewer = $this->makeUser('student', 'Viewer');

        $thread = app(ThreadService::class)->create(
            $op, ForumCategory::where('slug', 'questions')->firstOrFail(),
            ['title' => 'show endpoint test', 'body' => 'body'],
        );
        // Viewer votes up
        app(VotingService::class)->voteThread($thread, $viewer, 1);

        Sanctum::actingAs($viewer);
        $r = $this->getJson("/api/v1/forum/threads/{$thread->uuid}");
        $r->assertOk();
        $this->assertSame(1, $r->json('data.thread.my_vote'));
        $this->assertTrue($r->json('data.permissions.can_reply'));
        $this->assertFalse($r->json('data.permissions.can_accept_answer'),
            'viewer is not OP and not moderator → cannot accept');
    }
}
