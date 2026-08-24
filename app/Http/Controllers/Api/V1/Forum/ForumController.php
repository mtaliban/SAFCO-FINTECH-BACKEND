<?php

namespace App\Http\Controllers\Api\V1\Forum;

use App\Http\Controllers\Controller;
use App\Models\Forum\ForumCategory;
use App\Models\Forum\ForumSubscription;
use App\Models\Forum\ForumThread;
use App\Models\Forum\ForumVote;
use App\Services\Forum\VotingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SRS Module 14 — Discussion Forum browse endpoints.
 *
 * Read paths are public (unauthenticated OK) so guests can browse threads
 * before signing up. Write paths live in Thread/PostController and require auth.
 */
class ForumController extends Controller
{
    public function __construct(private readonly VotingService $voting) {}

    /** GET /forum/categories — public taxonomy listing (cached 5 min) */
    public function categories(): JsonResponse
    {
        // Categories are seeded taxonomy — change once in a blue moon. Cache
        // for 5 minutes so this endpoint doesn't do 4 DB queries per hit.
        $data = Cache::remember(ForumCategory::LIST_CACHE_KEY, now()->addMinutes(5), function () {
            return ForumCategory::orderBy('sort_order')->get()
                ->map(fn (ForumCategory $c) => [
                    'slug' => $c->slug,
                    'name' => $c->name,
                    'description' => $c->description,
                    'icon' => $c->icon,
                    'color' => $c->color,
                    'supports_accepted_answer' => $c->supports_accepted_answer,
                    'requires_course_context' => $c->requires_course_context,
                    'thread_count' => $c->threads()->where('is_hidden', false)->count(),
                ])
                ->all();
        });
        return $this->success(['categories' => $data]);
    }

    /** GET /forum/threads — list with filters */
    public function threads(Request $request): JsonResponse
    {
        $q = ForumThread::query()
            ->with([
                'author:id,uuid,email',
                'author.profile:user_id,full_name,profile_picture',
                'category:id,slug,name,color,supports_accepted_answer',
                'course:id,uuid,title,slug',
            ])
            ->where('is_hidden', false);

        if ($cat = $request->query('category')) {
            $catId = ForumCategory::where('slug', $cat)->value('id');
            if ($catId) $q->where('category_id', $catId);
        }
        if ($courseId = $request->query('course_id')) {
            $q->where('course_id', $courseId);
        }
        if ($courseUuid = $request->query('course_uuid')) {
            $q->whereHas('course', fn ($c) => $c->where('uuid', $courseUuid));
        }
        if ($assignmentId = $request->query('assignment_id')) {
            $q->where('assignment_id', $assignmentId);
        }
        if ($assignmentUuid = $request->query('assignment_uuid')) {
            $q->whereHas('assignment', fn ($a) => $a->where('uuid', $assignmentUuid));
        }
        if ($request->boolean('unanswered')) {
            $q->whereNull('accepted_post_id')->where('replies_count', 0);
        }
        if ($search = trim((string) $request->query('q', ''))) {
            // MySQL: FULLTEXT for length >= 4. SQLite (tests) or short strings: LIKE.
            $driver = DB::getDriverName();
            if ($driver === 'mysql' && strlen($search) >= 4) {
                $q->whereRaw('MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)', [$search]);
            } else {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like));
            }
        }

        $sort = $request->query('sort', 'recent');
        match ($sort) {
            'top' => $q->orderByDesc('votes_score')->orderByDesc('last_activity_at'),
            'unanswered' => $q->orderByDesc('created_at'),
            default => $q->orderByDesc('is_pinned')->orderByDesc('last_activity_at'),
        };

        $paginated = $q->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $paginated->getCollection()->map(fn (ForumThread $t) => $this->summarize($t)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /** GET /forum/threads/{uuid} — thread detail with posts */
    public function show(ForumThread $thread, Request $request): JsonResponse
    {
        $user = $request->user();
        if ($thread->is_hidden && !$user?->hasAnyRole(['system_admin', 'trainer', 'facilitator'])) {
            return $this->error('Thread not available.', 404);
        }

        $thread->load([
            'author:id,uuid,email',
            'author.profile:user_id,full_name,profile_picture',
            'category:id,slug,name,color,supports_accepted_answer',
            'course:id,uuid,title,slug',
            'assignment:id,uuid,title',
            'posts' => fn ($q) => $q->where('is_hidden', false)
                ->with([
                    'author:id,uuid,email',
                    'author.profile:user_id,full_name,profile_picture',
                ])
                ->orderBy('created_at'),
        ]);

        // AUDIT-B: dedup views by (user or IP) per thread per hour. Bots can't inflate
        // by hammering refresh, and the author's own re-reads don't count.
        $viewer = $user ? "u:{$user->id}" : ('ip:' . sha1((string) $request->ip()));
        $viewKey = "forum:view:{$thread->id}:{$viewer}";
        $isAuthorViewing = $user && (int) $user->id === (int) $thread->author_id;
        if (!$isAuthorViewing && Cache::add($viewKey, 1, now()->addHour())) {
            DB::table('forum_threads')->where('id', $thread->id)->increment('views_count');
            $thread->views_count = ($thread->views_count ?? 0) + 1;
        }

        // AUDIT-A: batch-fetch the viewer's votes for THIS thread + all its posts in
        // a single query instead of N+1 (one per post).
        $myVotes = ['thread' => 0, 'posts' => []];
        if ($user) {
            $postIds = $thread->posts->pluck('id')->all();
            $rows = ForumVote::where('user_id', $user->id)
                ->where(function ($q) use ($thread, $postIds) {
                    $q->where(fn ($w) => $w->where('votable_type', 'thread')->where('votable_id', $thread->id));
                    if (!empty($postIds)) {
                        $q->orWhere(fn ($w) => $w->where('votable_type', 'post')->whereIn('votable_id', $postIds));
                    }
                })
                ->get(['votable_type', 'votable_id', 'value']);
            foreach ($rows as $r) {
                if ($r->votable_type === 'thread') $myVotes['thread'] = (int) $r->value;
                else $myVotes['posts'][(int) $r->votable_id] = (int) $r->value;
            }
        }

        // AUDIT-I: report whether the current viewer is subscribed to this thread.
        $isSubscribed = $user && ForumSubscription::where('user_id', $user->id)
            ->where('thread_id', $thread->id)->exists();

        return $this->success([
            'thread' => array_merge($this->summarize($thread), [
                'body' => $thread->body,
                'assignment' => $thread->assignment ? [
                    'uuid' => $thread->assignment->uuid,
                    'title' => $thread->assignment->title,
                ] : null,
                'is_locked' => $thread->is_locked,
                'is_pinned' => $thread->is_pinned,
                'my_vote' => $myVotes['thread'],
                'is_subscribed' => $isSubscribed,
            ]),
            'posts' => $thread->posts->map(fn ($p) => [
                'uuid' => $p->uuid,
                'body' => $p->body,
                'author' => $this->authorSummary($p->author),
                'created_at' => $p->created_at?->toIso8601String(),
                'edited_at' => $p->edited_at?->toIso8601String(),
                'parent_post_id' => $p->parent_post_id,
                'is_accepted_answer' => $p->is_accepted_answer,
                'votes_score' => $p->votes_score,
                'my_vote' => $myVotes['posts'][$p->id] ?? 0,
                'can_edit' => $user && ((int) $user->id === (int) $p->author_id),
            ]),
            'permissions' => [
                'can_reply' => $user && !$thread->is_locked,
                'can_edit_thread' => $user && ((int) $user->id === (int) $thread->author_id),
                'can_accept_answer' => $user && $thread->category?->supports_accepted_answer &&
                    ((int) $user->id === (int) $thread->author_id ||
                     $user->hasAnyRole(['system_admin', 'trainer', 'facilitator'])),
                'can_moderate' => $user && $user->hasAnyRole(['system_admin', 'trainer', 'facilitator']),
            ],
        ]);
    }

    private function summarize(ForumThread $t): array
    {
        return [
            'uuid' => $t->uuid,
            'title' => $t->title,
            'excerpt' => \Str::limit(strip_tags($t->body), 200),
            'author' => $this->authorSummary($t->author),
            'category' => [
                'slug' => $t->category?->slug,
                'name' => $t->category?->name,
                'color' => $t->category?->color,
                'supports_accepted_answer' => (bool) $t->category?->supports_accepted_answer,
            ],
            'course' => $t->course ? [
                'uuid' => $t->course->uuid,
                'title' => $t->course->title,
                'slug' => $t->course->slug,
            ] : null,
            'tags' => $t->tags ?? [],
            'replies_count' => $t->replies_count,
            'votes_score' => $t->votes_score,
            'views_count' => $t->views_count,
            'has_accepted_answer' => $t->accepted_post_id !== null,
            'is_pinned' => $t->is_pinned,
            'is_locked' => $t->is_locked,
            'created_at' => $t->created_at?->toIso8601String(),
            'last_activity_at' => $t->last_activity_at?->toIso8601String(),
        ];
    }

    private function authorSummary($user): ?array
    {
        if (!$user) return null;
        return [
            'uuid' => $user->uuid,
            'name' => $user->profile?->full_name ?? explode('@', $user->email)[0],
            'avatar' => $user->profile?->profile_picture,
        ];
    }
}
