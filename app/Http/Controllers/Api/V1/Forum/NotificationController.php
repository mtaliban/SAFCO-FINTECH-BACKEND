<?php

namespace App\Http\Controllers\Api\V1\Forum;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 14 — Forum notifications feed for the logged-in user.
 *
 * Uses Laravel's built-in notifications table via the Notifiable trait on User.
 * We filter by our ForumEventNotification type so this endpoint only surfaces
 * forum-related items (other subsystems can add their own filter).
 */
class NotificationController extends Controller
{
    // With M15 the dispatcher writes ONE unified type — we filter forum items
    // via the event_key inside `data.event_key` (starts with 'forum.').
    private const INBOX_TYPE = 'App\\Notifications\\SafcoInboxNotification';
    // Legacy type from before M15 wiring; still shown so old rows aren't lost.
    private const LEGACY_TYPE = \App\Notifications\ForumEventNotification::class;

    /** GET /forum/notifications */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->query('filter', 'unread'); // unread | all

        $q = $user->notifications()
            ->whereIn('type', [self::INBOX_TYPE, self::LEGACY_TYPE]);
        if ($filter === 'unread') $q->whereNull('read_at');

        $items = $q->latest()->limit(50)->get()
            ->filter(fn ($n) => $this->isForumItem($n));

        $unread = $user->unreadNotifications()
            ->whereIn('type', [self::INBOX_TYPE, self::LEGACY_TYPE])
            ->get()->filter(fn ($n) => $this->isForumItem($n))->count();

        return $this->success([
            'unread_count' => $unread,
            'data' => $items->values()->map(fn ($n) => $this->serialize($n)),
        ]);
    }

    /** POST /forum/notifications/{id}/read */
    public function markRead(string $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $n = $user->notifications()->where('id', $id)->firstOrFail();
        $n->markAsRead();
        return $this->success(null, 'Marked read');
    }

    /** POST /forum/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->unreadNotifications()
            ->whereIn('type', [self::INBOX_TYPE, self::LEGACY_TYPE])
            ->update(['read_at' => now()]);
        return $this->success(null, 'All read');
    }

    private function isForumItem($n): bool
    {
        $key = $n->data['event_key'] ?? ($n->data['type'] ?? '');
        return str_starts_with((string) $key, 'forum.') || in_array($key, ['reply', 'mention', 'answer_accepted'], true);
    }

    private function serialize($n): array
    {
        $data = $n->data;
        // New (M15 dispatcher) rows nest the forum-specific fields under payload;
        // legacy rows have them flat on data.
        $payload = $data['payload'] ?? $data;
        $eventKey = $data['event_key'] ?? ($data['type'] ?? 'forum.reply');
        // Map full event_key back to the short type the frontend UI expects.
        $shortType = match (true) {
            str_ends_with($eventKey, '.reply') => 'reply',
            str_ends_with($eventKey, '.mention') => 'mention',
            str_ends_with($eventKey, '.answer_accepted') => 'answer_accepted',
            default => 'reply',
        };
        return [
            'id' => $n->id,
            'type' => $shortType,
            'thread_uuid' => $payload['thread_uuid'] ?? null,
            'thread_title' => $payload['thread_title'] ?? ($data['title'] ?? null),
            'post_uuid' => $payload['post_uuid'] ?? null,
            'actor_name' => $payload['actor_name'] ?? null,
            'excerpt' => $payload['excerpt'] ?? ($data['body'] ?? null),
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
