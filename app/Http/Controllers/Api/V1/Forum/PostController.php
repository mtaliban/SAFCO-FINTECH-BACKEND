<?php

namespace App\Http\Controllers\Api\V1\Forum;

use App\Http\Controllers\Controller;
use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumThread;
use App\Services\Forum\PostService;
use App\Services\Forum\VotingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class PostController extends Controller
{
    public function __construct(
        private readonly PostService $posts,
        private readonly VotingService $voting,
    ) {}

    private const RATE_KEY_REPLY = 'forum:post-create:';
    private const MAX_REPLIES_PER_MINUTE = 5;

    /** POST /forum/threads/{thread}/posts */
    public function store(ForumThread $thread, Request $request): JsonResponse
    {
        $user = $request->user();

        $key = self::RATE_KEY_REPLY . $user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_REPLIES_PER_MINUTE)) {
            $retry = RateLimiter::availableIn($key);
            return $this->error("Slow down. Try again in {$retry}s.", 429);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:20000'],
            'parent_post_id' => ['nullable', 'integer', 'exists:forum_posts,id'],
        ]);

        try {
            $post = $this->posts->reply($thread, $user, $data['body'], $data['parent_post_id'] ?? null);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        RateLimiter::hit($key, 60);

        return $this->success(['uuid' => $post->uuid], 'Reply posted', 201);
    }

    /** PATCH /forum/posts/{post} */
    public function update(ForumPost $post, Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:20000'],
        ]);
        try {
            $this->posts->update($post, $request->user(), $data['body']);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
        return $this->success(null, 'Post updated');
    }

    /** DELETE /forum/posts/{post} */
    public function destroy(ForumPost $post, Request $request): JsonResponse
    {
        $user = $request->user();
        $isModerator = $user->hasAnyRole(['system_admin', 'trainer', 'facilitator']);
        if ((int) $post->author_id !== (int) $user->id && !$isModerator) {
            return $this->error('You cannot delete this post.', 403);
        }
        $post->delete();
        return $this->success(null, 'Post deleted');
    }

    /** PATCH /forum/posts/{post}/moderate */
    public function moderate(ForumPost $post, Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_hidden' => ['sometimes', 'boolean'],
            'moderation_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        try {
            $this->posts->moderate($post, $request->user(), $data);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
        return $this->success(null, 'Post moderated');
    }

    /** POST /forum/posts/{post}/vote */
    public function vote(ForumPost $post, Request $request): JsonResponse
    {
        $data = $request->validate(['value' => ['required', 'integer', 'in:-1,0,1']]);
        try {
            $score = $this->voting->votePost($post, $request->user(), (int) $data['value']);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
        return $this->success(['votes_score' => $score]);
    }
}
