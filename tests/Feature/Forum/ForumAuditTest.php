<?php

namespace Tests\Feature\Forum;

use App\Models\Forum\ForumCategory;
use App\Models\Forum\ForumMention;
use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumReport;
use App\Models\Forum\ForumSubscription;
use App\Models\Forum\ForumThread;
use App\Models\Forum\ForumVote;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\ForumEventNotification;
use App\Services\Forum\MentionParser;
use App\Services\Forum\PostService;
use App\Services\Forum\ThreadService;
use App\Services\Forum\VotingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS Module 14 — SECURITY / CORRECTNESS AUDIT test suite.
 *
 * Guards the deep production fixes:
 *  A. my_vote batching (no N+1)
 *  B. views_count dedup (bots + refresh cannot inflate)
 *  C. Hidden accepted post clears is_accepted_answer + thread.accepted_post_id
 *  D. Voting on hidden content is rejected
 *  E. MentionParser is portable + resolves @handle correctly
 *  F. Inactive users are not notified
 *  I. show endpoint returns is_subscribed
 *  J. Hiding a thread/post auto-resolves open reports
 */
class ForumAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['system_admin', 'trainer', 'student'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function makeUser(string $role, string $name = 'User Name', string $status = 'active'): User
    {
        $u = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => strtolower(explode(' ', $name)[0]) . Str::random(6) . '@t.io',
            'password' => bcrypt('x'),
            'email_verified_at' => now(),
            'status' => $status,
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

    private function seedThread(User $author, string $catSlug = 'questions'): ForumThread
    {
        return app(ThreadService::class)->create(
            $author,
            ForumCategory::where('slug', $catSlug)->firstOrFail(),
            ['title' => 'A thread title', 'body' => 'thread body content here'],
        );
    }

    // ── AUDIT-A: batched my_vote ─────────────────────────────────

    public function test_thread_show_batches_my_vote_in_one_query(): void
    {
        $op = $this->makeUser('student', 'OP');
        $voter = $this->makeUser('student', 'Voter');
        $h1 = $this->makeUser('student', 'Helper1');
        $h2 = $this->makeUser('student', 'Helper2');

        $thread = $this->seedThread($op);
        $p1 = app(PostService::class)->reply($thread, $h1, 'first reply');
        $p2 = app(PostService::class)->reply($thread, $h2, 'second reply');

        app(VotingService::class)->voteThread($thread, $voter, 1);
        app(VotingService::class)->votePost($p1, $voter, 1);
        app(VotingService::class)->votePost($p2, $voter, -1);

        Sanctum::actingAs($voter);
        DB::enableQueryLog();
        $r = $this->getJson("/api/v1/forum/threads/{$thread->uuid}");
        $r->assertOk();

        // Count queries that pulled from forum_votes — must be exactly ONE,
        // not one-per-post. This is the AUDIT-A guarantee.
        $voteQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains(strtolower($q['query']), 'forum_votes'))
            ->count();
        DB::disableQueryLog();
        $this->assertSame(1, $voteQueries,
            'my_vote lookups must be batched into a single query');

        $this->assertSame(1, $r->json('data.thread.my_vote'));
        $postVotes = collect($r->json('data.posts'))->pluck('my_vote')->all();
        $this->assertContains(1, $postVotes);
        $this->assertContains(-1, $postVotes);
    }

    // ── AUDIT-B: views_count dedup ──────────────────────────────

    public function test_views_count_deduped_per_user_per_hour(): void
    {
        $op = $this->makeUser('student', 'OP');
        $viewer = $this->makeUser('student', 'Viewer');
        $thread = $this->seedThread($op);

        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/forum/threads/{$thread->uuid}")->assertOk();
        $this->getJson("/api/v1/forum/threads/{$thread->uuid}")->assertOk();
        $this->getJson("/api/v1/forum/threads/{$thread->uuid}")->assertOk();

        // Three refreshes by the same viewer within one hour = exactly one view.
        $this->assertSame(1, $thread->fresh()->views_count);
    }

    public function test_author_viewing_own_thread_does_not_count(): void
    {
        $op = $this->makeUser('student', 'OP');
        $thread = $this->seedThread($op);

        Sanctum::actingAs($op);
        $this->getJson("/api/v1/forum/threads/{$thread->uuid}")->assertOk();

        $this->assertSame(0, $thread->fresh()->views_count,
            'author viewing their own thread must not inflate views_count');
    }

    // ── AUDIT-C: hidden accepted post is consistent ─────────────

    public function test_hiding_accepted_post_clears_accepted_flag(): void
    {
        $op = $this->makeUser('student', 'OP');
        $helper = $this->makeUser('student', 'Helper');
        $mod = $this->makeUser('trainer', 'Mod User');

        $thread = $this->seedThread($op);
        $post = app(PostService::class)->reply($thread, $helper, 'Answer text');
        app(ThreadService::class)->acceptAnswer($thread, $post, $op);

        $this->assertTrue($post->fresh()->is_accepted_answer);
        $this->assertSame($post->id, $thread->fresh()->accepted_post_id);

        // Moderator hides the accepted post
        app(PostService::class)->moderate($post, $mod, [
            'is_hidden' => true,
            'moderation_note' => 'Contained personal info',
        ]);

        $this->assertFalse($post->fresh()->is_accepted_answer,
            'is_accepted_answer must be cleared when the post is hidden');
        $this->assertNull($thread->fresh()->accepted_post_id,
            'thread.accepted_post_id must be cleared when the accepted post is hidden');
    }

    // ── AUDIT-D: voting rejected on hidden content ──────────────

    public function test_voting_on_hidden_thread_is_rejected(): void
    {
        $op = $this->makeUser('student', 'OP');
        $mod = $this->makeUser('trainer', 'Mod');
        $voter = $this->makeUser('student', 'Voter');

        $thread = $this->seedThread($op);
        app(ThreadService::class)->moderate($thread, $mod, ['is_hidden' => true]);

        $this->expectException(\DomainException::class);
        app(VotingService::class)->voteThread($thread->fresh(), $voter, 1);
    }

    public function test_voting_on_hidden_post_is_rejected(): void
    {
        $op = $this->makeUser('student', 'OP');
        $author = $this->makeUser('student', 'Author');
        $mod = $this->makeUser('trainer', 'Mod');
        $voter = $this->makeUser('student', 'Voter');

        $thread = $this->seedThread($op);
        $post = app(PostService::class)->reply($thread, $author, 'reply body');
        app(PostService::class)->moderate($post, $mod, ['is_hidden' => true]);

        $this->expectException(\DomainException::class);
        app(VotingService::class)->votePost($post->fresh(), $voter, 1);
    }

    // ── AUDIT-E: MentionParser is portable + correct ────────────

    public function test_mention_parser_resolves_by_email_prefix(): void
    {
        $author = $this->makeUser('student', 'Author');
        $target = $this->seedThread($author);
        $mentioned = $this->makeUser('student', 'Amina Hassan');

        // Extract "amina" from body — must resolve to $mentioned (email starts with "amina")
        $matched = app(MentionParser::class)->extractAndPersist(
            $target, 'thread',
            'Hey @amina, can you help with this? Also @notauser should not match.',
            $author,
        );

        $this->assertTrue($matched->contains(fn (User $u) => $u->id === $mentioned->id));
        $this->assertSame(1, $matched->count(), 'Only the resolvable @amina should match');
        $this->assertDatabaseHas('forum_mentions', [
            'mentionable_type' => 'thread',
            'mentionable_id' => $target->id,
            'mentioned_user_id' => $mentioned->id,
        ]);
    }

    public function test_mention_parser_does_not_notify_self(): void
    {
        $author = $this->makeUser('student', 'Author');
        $target = $this->seedThread($author);
        $authorFirst = explode(' ', 'Author')[0]; // 'Author'

        $matched = app(MentionParser::class)->extractAndPersist(
            $target, 'thread',
            "I want to mention @{$authorFirst} myself but that should be dropped.",
            $author,
        );
        $this->assertTrue($matched->isEmpty(),
            'Self-mentions must not create ForumMention rows');
    }

    public function test_mention_parser_skips_inactive_users(): void
    {
        $author = $this->makeUser('student', 'Author');
        $target = $this->seedThread($author);
        $inactive = $this->makeUser('student', 'Suspended Sam', 'suspended');

        $matched = app(MentionParser::class)->extractAndPersist(
            $target, 'thread',
            "Ping @suspended please.",
            $author,
        );
        $this->assertTrue($matched->isEmpty(),
            'Inactive users must not be resolved as mention targets');
    }

    // ── AUDIT-F: inactive users are not notified ────────────────

    public function test_inactive_thread_author_is_not_notified_on_reply(): void
    {
        $op = $this->makeUser('student', 'OP User');
        $replier = $this->makeUser('student', 'Replier');

        $thread = $this->seedThread($op);
        $op->update(['status' => 'suspended']);

        app(PostService::class)->reply($thread, $replier, 'A reply body here.');

        // With M15 dispatcher: inactive users get ONE 'skipped/inactive_user' row,
        // not a sent notification. Assert neither channel was actually delivered.
        $this->assertDatabaseMissing('notification_deliveries', [
            'user_id' => $op->id,
            'event_key' => 'forum.reply',
            'status' => 'sent',
        ]);
    }

    public function test_accepted_answer_notification_skips_inactive_helper(): void
    {
        $op = $this->makeUser('student', 'OP');
        $helper = $this->makeUser('student', 'Helper');
        $thread = $this->seedThread($op);
        $post = app(PostService::class)->reply($thread, $helper, 'answer body');

        $helper->update(['status' => 'suspended']);

        app(ThreadService::class)->acceptAnswer($thread, $post, $op);
        $this->assertDatabaseMissing('notification_deliveries', [
            'user_id' => $helper->id,
            'event_key' => 'forum.answer_accepted',
            'status' => 'sent',
        ]);
    }

    // ── AUDIT-I: is_subscribed on show ──────────────────────────

    public function test_show_endpoint_reports_is_subscribed(): void
    {
        $op = $this->makeUser('student', 'OP');
        $viewer = $this->makeUser('student', 'Viewer');
        $thread = $this->seedThread($op);

        // Not subscribed yet
        Sanctum::actingAs($viewer);
        $r = $this->getJson("/api/v1/forum/threads/{$thread->uuid}");
        $this->assertFalse($r->json('data.thread.is_subscribed'));

        // Subscribe and re-check
        ForumSubscription::create(['user_id' => $viewer->id, 'thread_id' => $thread->id]);
        $r = $this->getJson("/api/v1/forum/threads/{$thread->uuid}");
        $this->assertTrue($r->json('data.thread.is_subscribed'));
    }

    // ── AUDIT-J: hiding auto-resolves reports ───────────────────

    public function test_hiding_thread_auto_resolves_open_reports(): void
    {
        $author = $this->makeUser('student', 'Author');
        $reporter = $this->makeUser('student', 'Reporter');
        $mod = $this->makeUser('trainer', 'Mod User');
        $thread = $this->seedThread($author);

        // Reporter files a report
        ForumReport::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => 'thread',
            'reportable_id' => $thread->id,
            'reason' => 'spam',
            'status' => 'open',
        ]);
        $this->assertSame(1, ForumReport::where('status', 'open')->count());

        // Moderator hides the thread
        app(ThreadService::class)->moderate($thread, $mod, ['is_hidden' => true]);

        $this->assertSame(0, ForumReport::where('status', 'open')->count(),
            'open reports on the target must auto-resolve when moderator hides it');
        $this->assertSame(1, ForumReport::where('status', 'resolved')->count());
    }

    public function test_hiding_post_auto_resolves_open_reports(): void
    {
        $op = $this->makeUser('student', 'OP');
        $author = $this->makeUser('student', 'Author');
        $reporter = $this->makeUser('student', 'Reporter');
        $mod = $this->makeUser('trainer', 'Mod User');
        $thread = $this->seedThread($op);
        $post = app(PostService::class)->reply($thread, $author, 'a reply body');

        ForumReport::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => 'post',
            'reportable_id' => $post->id,
            'reason' => 'offensive',
            'status' => 'open',
        ]);

        app(PostService::class)->moderate($post, $mod, ['is_hidden' => true]);

        $this->assertSame(0, ForumReport::where('status', 'open')->count());
    }
}
