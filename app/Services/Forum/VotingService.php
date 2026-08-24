<?php

namespace App\Services\Forum;

use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumThread;
use App\Models\Forum\ForumVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SRS Module 14 — Forum voting.
 *
 * Contract:
 *  - Every user has AT MOST one vote per (target).
 *  - Recording the same vote again = toggle-off (removes the vote).
 *  - Recording the opposite vote replaces the existing one (score swings by 2).
 *  - Author cannot vote on their own thread/post (self-vote inflation).
 *  - votes_score on target is authoritative — recomputed from votes table, never
 *    incremented, so races/duplicates can never drift the number.
 */
class VotingService
{
    public function voteThread(ForumThread $thread, User $user, int $value): int
    {
        // AUDIT-D: don't let users interact with content a moderator has hidden.
        if ($thread->is_hidden) {
            throw new \DomainException('This thread has been hidden by a moderator.');
        }
        if ($thread->is_locked) {
            throw new \DomainException('This thread is locked; no new votes.');
        }
        return $this->vote($thread, $user, $value, ForumVote::TARGET_THREAD);
    }

    public function votePost(ForumPost $post, User $user, int $value): int
    {
        if ($post->is_hidden) {
            throw new \DomainException('This post has been hidden by a moderator.');
        }
        // Also respect the parent thread's state.
        $thread = $post->thread;
        if ($thread && ($thread->is_hidden || $thread->is_locked)) {
            throw new \DomainException('This thread no longer accepts votes.');
        }
        return $this->vote($post, $user, $value, ForumVote::TARGET_POST);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $target
     * @return int  the new votes_score on the target
     */
    private function vote($target, User $user, int $value, string $targetType): int
    {
        if (!in_array($value, [-1, 1, 0], true)) {
            throw new \InvalidArgumentException('Vote value must be -1, 0, or 1');
        }
        if ((int) $target->author_id === (int) $user->id) {
            throw new \DomainException('You cannot vote on your own post.');
        }

        return DB::transaction(function () use ($target, $user, $value, $targetType) {
            $existing = ForumVote::where([
                'user_id' => $user->id,
                'votable_type' => $targetType,
                'votable_id' => $target->id,
            ])->lockForUpdate()->first();

            if ($existing) {
                if ($value === 0 || $existing->value === $value) {
                    // Same vote OR explicit clear → remove
                    $existing->delete();
                } else {
                    $existing->update(['value' => $value]);
                }
            } elseif ($value !== 0) {
                ForumVote::create([
                    'user_id' => $user->id,
                    'votable_type' => $targetType,
                    'votable_id' => $target->id,
                    'value' => $value,
                ]);
            }

            // Recompute from source of truth — never trust +=/-= under concurrency.
            $newScore = (int) ForumVote::where([
                'votable_type' => $targetType,
                'votable_id' => $target->id,
            ])->sum('value');

            $target->forceFill(['votes_score' => $newScore])->save();

            return $newScore;
        });
    }

    /** Returns the signed vote value (-1, 0, 1) for a user on a target. */
    public function currentVote(string $targetType, int $targetId, ?User $user): int
    {
        if (!$user) return 0;
        return (int) (ForumVote::where([
            'user_id' => $user->id,
            'votable_type' => $targetType,
            'votable_id' => $targetId,
        ])->value('value') ?? 0);
    }
}
