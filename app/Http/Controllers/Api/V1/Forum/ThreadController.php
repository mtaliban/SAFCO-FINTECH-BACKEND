<?php

namespace App\Http\Controllers\Api\V1\Forum;

use App\Http\Controllers\Controller;
use App\Models\Forum\ForumCategory;
use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumSubscription;
use App\Models\Forum\ForumThread;
use App\Services\Forum\ThreadService;
use App\Services\Forum\VotingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SRS Module 14 — Authenticated write endpoints for threads.
 */
class ThreadController extends Controller
{
    public function __construct(
        private readonly ThreadService $threads,
        private readonly VotingService $voting,
    ) {}

    private const RATE_KEY_CREATE = 'forum:thread-create:';
    private const MAX_THREADS_PER_HOUR = 6;

    /** POST /forum/threads */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $key = self::RATE_KEY_CREATE . $user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_THREADS_PER_HOUR)) {
            $retry = RateLimiter::availableIn($key);
            return $this->error("Too many threads. Try again in {$retry}s.", 429);
        }

        $data = $request->validate([
            'category' => ['required', 'string', 'exists:forum_categories,slug'],
            'title' => ['required', 'string', 'min:5', 'max:220'],
            'body' => ['required', 'string', 'min:10', 'max:20000'],
            // Accept either numeric id or uuid (UUID is the public identifier throughout the app).
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'course_uuid' => ['nullable', 'uuid', 'exists:courses,uuid'],
            'assignment_id' => ['nullable', 'integer', 'exists:assignments,id'],
            'assignment_uuid' => ['nullable', 'uuid', 'exists:assignments,uuid'],
            'tags' => ['nullable', 'array', 'max:6'],
            'tags.*' => ['string', 'max:32'],
        ]);

        // Resolve UUID → id where provided.
        if (!empty($data['course_uuid'])) {
            $data['course_id'] = \App\Models\Course::where('uuid', $data['course_uuid'])->value('id');
        }
        if (!empty($data['assignment_uuid'])) {
            $data['assignment_id'] = \App\Models\Assignment::where('uuid', $data['assignment_uuid'])->value('id');
        }

        $category = ForumCategory::where('slug', $data['category'])->firstOrFail();

        try {
            $thread = $this->threads->create($user, $category, $data);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        RateLimiter::hit($key, 3600);

        return $this->success(['uuid' => $thread->uuid], 'Thread created', 201);
    }

    /** PATCH /forum/threads/{thread} */
    public function update(ForumThread $thread, Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:5', 'max:220'],
            'body' => ['sometimes', 'string', 'min:10', 'max:20000'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:6'],
            'tags.*' => ['string', 'max:32'],
        ]);
        try {
            $this->threads->update($thread, $request->user(), $data);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
        return $this->success(null, 'Thread updated');
    }

    /** DELETE /forum/threads/{thread} */
    public function destroy(ForumThread $thread, Request $request): JsonResponse
    {
        $user = $request->user();
        $isModerator = $user->hasAnyRole(['system_admin', 'trainer', 'facilitator']);
        if ((int) $thread->author_id !== (int) $user->id && !$isModerator) {
            return $this->error('You cannot delete this thread.', 403);
        }
        $thread->delete();
        return $this->success(null, 'Thread deleted');
    }

    /** PATCH /forum/threads/{thread}/moderate */
    public function moderate(ForumThread $thread, Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_pinned' => ['sometimes', 'boolean'],
            'is_locked' => ['sometimes', 'boolean'],
            'is_hidden' => ['sometimes', 'boolean'],
            'moderation_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        try {
            $this->threads->moderate($thread, $request->user(), $data);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
        return $this->success(null, 'Thread moderated');
    }

    /** POST /forum/threads/{thread}/vote */
    public function vote(ForumThread $thread, Request $request): JsonResponse
    {
        $data = $request->validate(['value' => ['required', 'integer', 'in:-1,0,1']]);
        try {
            $score = $this->voting->voteThread($thread, $request->user(), (int) $data['value']);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
        return $this->success(['votes_score' => $score]);
    }

    /** POST /forum/threads/{thread}/accept/{post} */
    public function acceptAnswer(ForumThread $thread, ForumPost $post, Request $request): JsonResponse
    {
        try {
            $this->threads->acceptAnswer($thread, $post, $request->user());
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
        return $this->success(null, 'Answer accepted');
    }

    /** POST /forum/threads/{thread}/subscribe */
    public function subscribe(ForumThread $thread, Request $request): JsonResponse
    {
        ForumSubscription::firstOrCreate([
            'user_id' => $request->user()->id,
            'thread_id' => $thread->id,
        ]);
        return $this->success(null, 'Subscribed');
    }

    /** DELETE /forum/threads/{thread}/subscribe */
    public function unsubscribe(ForumThread $thread, Request $request): JsonResponse
    {
        ForumSubscription::where([
            'user_id' => $request->user()->id,
            'thread_id' => $thread->id,
        ])->delete();
        return $this->success(null, 'Unsubscribed');
    }
}
