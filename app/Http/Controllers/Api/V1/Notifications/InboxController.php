<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 15 — Unified in-app inbox for the bell icon.
 *
 * Reads Laravel's notifications table (populated by InAppChannel dispatch)
 * and exposes them in a channel-agnostic shape for the frontend to render.
 */
class InboxController extends Controller
{
    /** GET /notifications/inbox — list */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->query('filter', 'unread'); // unread | all
        $limit = min(100, max(1, (int) $request->query('limit', 30)));

        $q = $user->notifications();
        if ($filter === 'unread') $q->whereNull('read_at');

        $items = $q->latest()->limit($limit)->get();

        return $this->success([
            'unread_count' => $user->unreadNotifications()->count(),
            'items' => $items->map(fn ($n) => $this->serialize($n)),
        ]);
    }

    /** POST /notifications/inbox/{id}/read */
    public function markRead(string $id, Request $request): JsonResponse
    {
        $n = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $n->markAsRead();
        return $this->success(null, 'Marked read');
    }

    /** POST /notifications/inbox/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        return $this->success(null, 'All read');
    }

    /** DELETE /notifications/inbox/{id} */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $n = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $n->delete();
        return $this->success(null, 'Deleted');
    }

    private function serialize($n): array
    {
        $data = $n->data ?? [];
        $payload = $data['payload'] ?? [];
        return [
            'id' => $n->id,
            'event_key' => $data['event_key'] ?? null,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'action_url' => $data['action_url'] ?? ($payload['action_url'] ?? null),
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
