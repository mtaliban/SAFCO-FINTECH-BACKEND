<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Models\BroadcastAnnouncement;
use App\Services\Notifications\BroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SRS Module 15 — Admin broadcast announcements.
 *
 * Admins compose a message + pick a segment; the service fans out via the
 * dispatcher (so user prefs are still honoured). Sending is synchronous
 * because we already limit fan-out with rate limits — for really large
 * audiences the caller should batch or move to a queue job.
 */
class BroadcastController extends Controller
{
    public function __construct(private readonly BroadcastService $svc) {}

    /** GET /admin/announcements — recent broadcasts */
    public function index(): JsonResponse
    {
        $rows = BroadcastAnnouncement::latest()
            ->with('creator:id,uuid,email')
            ->limit(50)->get();

        return $this->success([
            'data' => $rows->map(fn ($b) => [
                'uuid' => $b->uuid,
                'title' => $b->title,
                'body_preview' => \Str::limit(strip_tags($b->body), 160),
                'segment' => $b->segment,
                'status' => $b->status,
                'audience_size' => $b->audience_size,
                'sent_count' => $b->sent_count,
                'failed_count' => $b->failed_count,
                'sent_at' => $b->sent_at?->toIso8601String(),
                'created_at' => $b->created_at?->toIso8601String(),
                'creator_email' => $b->creator?->email,
            ]),
        ]);
    }

    /** POST /admin/announcements/preview — audience size only, no send */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'segment' => ['nullable', 'array'],
            'segment.role' => ['nullable', 'string', 'in:student,trainer,facilitator,corporate_client,system_admin'],
            'segment.course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'segment.organization_id' => ['nullable', 'integer'],
        ]);
        $audience = $this->svc->resolveAudience($data['segment'] ?? []);
        return $this->success(['audience_size' => $audience->count()]);
    }

    /** POST /admin/announcements — create + send */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:5', 'max:10000'],
            'segment' => ['nullable', 'array'],
            'segment.role' => ['nullable', 'string', 'in:student,trainer,facilitator,corporate_client,system_admin'],
            'segment.course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'segment.organization_id' => ['nullable', 'integer'],
            'segment.action_url' => ['nullable', 'url', 'max:500'],
            'segment.action_label' => ['nullable', 'string', 'max:60'],
        ]);

        $b = BroadcastAnnouncement::create([
            'created_by' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'segment' => $data['segment'] ?? [],
            'channels' => ['email', 'in_app'],
            'status' => 'draft',
        ]);

        $b = $this->svc->send($b);

        return $this->success([
            'uuid' => $b->uuid,
            'audience_size' => $b->audience_size,
            'sent_count' => $b->sent_count,
            'failed_count' => $b->failed_count,
            'status' => $b->status,
        ], 'Broadcast dispatched', 201);
    }
}
