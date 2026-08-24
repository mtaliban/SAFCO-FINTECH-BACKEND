<?php

namespace App\Services\Notifications;

use App\Models\BroadcastAnnouncement;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * SRS Module 15 — Admin broadcast fan-out.
 *
 * Given a segment ({role: 'student', course_id: 5, org_id: 3}), resolves the
 * concrete user list and dispatches system.announcement to each via the
 * central NotificationDispatcher — so per-user prefs, rate limits, and the
 * delivery audit log ALL apply the same as for automatic events.
 */
class BroadcastService
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function resolveAudience(array $segment): Collection
    {
        $q = User::query()->where('status', 'active');

        if (!empty($segment['role'])) {
            $q->whereHas('roles', fn ($r) => $r->where('name', $segment['role']));
        }
        if (!empty($segment['course_id'])) {
            $userIds = Enrollment::where('course_id', $segment['course_id'])->pluck('user_id');
            $q->whereIn('id', $userIds);
        }
        if (!empty($segment['organization_id'])) {
            $q->where('organization_id', $segment['organization_id']);
        }
        return $q->get();
    }

    public function send(BroadcastAnnouncement $broadcast): BroadcastAnnouncement
    {
        $users = $this->resolveAudience($broadcast->segment ?? []);
        $broadcast->update([
            'status' => 'sending',
            'audience_size' => $users->count(),
        ]);

        $payload = [
            'title' => $broadcast->title,
            'body' => $broadcast->body,
            'action_url' => $broadcast->segment['action_url'] ?? null,
            'action_label' => $broadcast->segment['action_label'] ?? 'Open dashboard',
        ];

        $sent = 0;
        $failed = 0;
        foreach ($users as $u) {
            $recs = $this->dispatcher->dispatch($u, 'system.announcement', $payload);
            foreach ($recs as $r) {
                if ($r->status === 'sent') $sent++;
                elseif ($r->status === 'failed') $failed++;
            }
        }

        $broadcast->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'status' => $failed > 0 && $sent === 0 ? 'failed' : 'sent',
            'sent_at' => now(),
        ]);
        return $broadcast->fresh();
    }
}
