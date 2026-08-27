<?php

namespace App\Services\Forum;

use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumSubscription;
use App\Models\Forum\ForumThread;
use App\Models\User;
use App\Services\EventBus\MqttPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SRS Module 14 — Reply lifecycle.
 *
 * Enforces:
 *  - Locked or hidden threads reject new posts.
 *  - Reply nesting is capped at 1 level (parent_post_id cannot itself be a reply-to-reply).
 *  - Author auto-subscribes to the thread on posting.
 *  - Mentions parsed and persisted.
 */
class PostService
{
    public function __construct(
        private readonly MentionParser $mentions,
        private readonly NotificationService $notifications,
        private readonly MqttPublisher $mqtt,
    ) {}

    public function reply(ForumThread $thread, User $author, string $body, ?int $parentPostId = null): ForumPost
    {
        if ($thread->is_locked) {
            throw new \DomainException('This thread is locked. No new replies allowed.');
        }
        if ($thread->is_hidden) {
            throw new \DomainException('This thread has been hidden by a moderator.');
        }

        if ($parentPostId) {
            $parent = ForumPost::where('id', $parentPostId)
                ->where('thread_id', $thread->id)
                ->first();
            if (!$parent) {
                throw new \DomainException('Parent post not found in this thread.');
            }
            // Cap nesting to one level — a reply to a reply must still target the top-level parent.
            if ($parent->parent_post_id !== null) {
                $parentPostId = $parent->parent_post_id;
            }
        }

        $post = DB::transaction(function () use ($thread, $author, $body, $parentPostId) {
            $post = ForumPost::create([
                'thread_id' => $thread->id,
                'author_id' => $author->id,
                'parent_post_id' => $parentPostId,
                'body' => trim($body),
            ]);

            ForumSubscription::firstOrCreate([
                'user_id' => $author->id,
                'thread_id' => $thread->id,
            ]);

            $mentioned = $this->mentions->extractAndPersist($post, 'post', $post->body, $author);
            $post->setRelation('_mentioned', $mentioned);

            return $post;
        });

        // Fire notifications OUTSIDE the transaction — DB writes are committed by now,
        // and if mail/broadcast fails, the post still exists.
        $this->notifications->notifyReply($post);
        $mentioned = $post->getRelation('_mentioned');
        if ($mentioned && $mentioned->isNotEmpty()) {
            $this->notifications->notifyMentions($thread, $post, $mentioned, $author);
        }

        // Broadcast new reply via MQTT so open thread pages update in real-time
        try {
            $this->mqtt->publishRaw(
                topic: "safco/lms/forum/thread/{$thread->uuid}/reply",
                payload: [
                    'uuid'         => $post->uuid,
                    'author_name'  => $author->userProfile?->full_name ?? $author->email,
                    'body_preview' => mb_substr($post->body, 0, 140),
                    'created_at'   => $post->created_at?->toIso8601String(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('[forum] MQTT reply broadcast failed', [
                'thread_uuid' => $thread->uuid,
                'error'       => $e->getMessage(),
            ]);
        }

        return $post;
    }

    public function update(ForumPost $post, User $actor, string $body): ForumPost
    {
        $isModerator = $actor->hasAnyRole(['system_admin', 'trainer', 'facilitator']);
        if ((int) $post->author_id !== (int) $actor->id && !$isModerator) {
            throw new \DomainException('You cannot edit this post.');
        }
        $post->fill([
            'body' => trim($body),
            'edited_at' => now(),
            'edited_by' => $actor->id,
        ])->save();
        return $post->fresh();
    }

    public function moderate(ForumPost $post, User $actor, array $data): ForumPost
    {
        if (!$actor->hasAnyRole(['system_admin', 'trainer', 'facilitator'])) {
            throw new \DomainException('Only moderators may change post state.');
        }
        $wasAccepted = $post->is_accepted_answer;
        $willHide = array_key_exists('is_hidden', $data) && $data['is_hidden'];

        $post->fill(array_intersect_key($data, array_flip(['is_hidden', 'moderation_note'])));
        $post->moderated_by = $actor->id;
        $post->moderated_at = now();

        // AUDIT-C: if we're hiding a post that was the accepted answer, unset the
        // accepted flag and clear the pointer on the thread — otherwise the thread
        // reports has_accepted_answer=true but the answer is invisible.
        if ($willHide && $wasAccepted) {
            $post->is_accepted_answer = false;
            // Always refetch the thread so we compare against the current DB value —
            // a passed-in $post may hold a stale/unloaded thread relation.
            $thread = \App\Models\Forum\ForumThread::find($post->thread_id);
            if ($thread && (int) $thread->accepted_post_id === (int) $post->id) {
                $thread->forceFill(['accepted_post_id' => null])->save();
            }
        }
        $post->save();

        // AUDIT-J: auto-resolve any open reports on this post — moderator action taken.
        if ($willHide) {
            \App\Models\Forum\ForumReport::where('reportable_type', 'post')
                ->where('reportable_id', $post->id)
                ->where('status', 'open')
                ->update([
                    'status' => 'resolved',
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                    'resolution_note' => 'Auto-resolved: post hidden by moderator.',
                ]);
        }
        return $post->fresh();
    }
}
