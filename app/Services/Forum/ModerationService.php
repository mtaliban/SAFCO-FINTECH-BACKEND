<?php

namespace App\Services\Forum;

use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumReport;
use App\Models\Forum\ForumThread;
use App\Models\User;

/**
 * SRS Module 14 — Community reports + moderator resolution.
 *
 * A report is a user-level flag; it does not immediately hide content.
 * A moderator resolves reports (resolved = action taken; dismissed = no action).
 */
class ModerationService
{
    public function report($target, User $reporter, string $reason, ?string $note = null): ForumReport
    {
        $targetType = $target instanceof ForumThread ? 'thread'
                    : ($target instanceof ForumPost ? 'post' : null);
        if (!$targetType) throw new \InvalidArgumentException('Target must be a thread or post.');
        if (!in_array($reason, ForumReport::REASONS, true)) {
            throw new \InvalidArgumentException('Invalid report reason.');
        }
        if ((int) $target->author_id === (int) $reporter->id) {
            throw new \DomainException('You cannot report your own content.');
        }

        // firstOrCreate = dedup a duplicate report from the same user on the same target
        return ForumReport::firstOrCreate(
            [
                'reporter_id' => $reporter->id,
                'reportable_type' => $targetType,
                'reportable_id' => $target->id,
            ],
            [
                'reason' => $reason,
                'note' => $note,
                'status' => 'open',
            ]
        );
    }

    public function resolve(ForumReport $report, User $moderator, string $status, ?string $note = null): ForumReport
    {
        if (!$moderator->hasAnyRole(['system_admin', 'trainer', 'facilitator'])) {
            throw new \DomainException('Only moderators may resolve reports.');
        }
        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            throw new \InvalidArgumentException('Status must be resolved or dismissed.');
        }
        $report->fill([
            'status' => $status,
            'resolved_by' => $moderator->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();
        return $report->fresh();
    }
}
