<?php

namespace App\Services\Forum;

use App\Models\Forum\ForumMention;
use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumSubscription;
use App\Models\Forum\ForumThread;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;

/**
 * SRS Module 14 — Fan-out for forum events.
 *
 * As of Module 15 this delegates to the central NotificationDispatcher so
 * user preferences (email / in-app toggles), rate limits, and the delivery
 * audit log are all respected. This class is now a thin adapter that decides
 * WHO to notify; the dispatcher decides HOW.
 */
class NotificationService
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function notifyReply(ForumPost $post): void
    {
        $thread = $post->thread;
        if (!$thread) return;
        $actor = $post->author;

        $userIds = ForumSubscription::where('thread_id', $thread->id)
            ->pluck('user_id')->push($thread->author_id)->unique()
            ->reject(fn ($id) => (int) $id === (int) $actor->id)
            ->values();
        if ($userIds->isEmpty()) return;

        $users = User::whereIn('id', $userIds)->where('status', 'active')->get();
        $payload = $this->buildPayload($thread, $post, $actor);
        foreach ($users as $u) {
            $this->dispatcher->dispatch($u, 'forum.reply', $payload);
        }
    }

    public function notifyMentions(ForumThread $thread, ?ForumPost $post, iterable $mentionedUsers, User $actor): void
    {
        $payload = $this->buildPayload($thread, $post, $actor);
        foreach ($mentionedUsers as $user) {
            if ((int) $user->id === (int) $actor->id) continue;
            if (($user->status ?? 'active') !== 'active') continue;
            $this->dispatcher->dispatch($user, 'forum.mention', $payload);
            ForumMention::where([
                'mentionable_type' => $post ? 'post' : 'thread',
                'mentionable_id' => $post ? $post->id : $thread->id,
                'mentioned_user_id' => $user->id,
            ])->update(['notified_at' => now()]);
        }
    }

    public function notifyAnswerAccepted(ForumThread $thread, ForumPost $post, User $actor): void
    {
        $author = User::find($post->author_id);
        if (!$author || (int) $author->id === (int) $actor->id) return;
        if (($author->status ?? 'active') !== 'active') return;
        $this->dispatcher->dispatch($author, 'forum.answer_accepted', $this->buildPayload($thread, $post, $actor));
    }

    private function buildPayload(ForumThread $thread, ?ForumPost $post, User $actor): array
    {
        $excerpt = $post
            ? \Str::limit(strip_tags($post->body), 200)
            : \Str::limit(strip_tags($thread->body), 200);
        return [
            'thread_uuid' => $thread->uuid,
            'thread_title' => $thread->title,
            'post_uuid' => $post?->uuid,
            'actor_name' => $actor->profile?->full_name ?? $actor->email,
            'excerpt' => $excerpt,
            'action_url' => config('app.url') . '/forum/thread/' . $thread->uuid,
            'action_label' => 'View discussion',
        ];
    }
}
