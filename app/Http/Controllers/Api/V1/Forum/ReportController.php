<?php

namespace App\Http\Controllers\Api\V1\Forum;

use App\Http\Controllers\Controller;
use App\Models\Forum\ForumPost;
use App\Models\Forum\ForumReport;
use App\Models\Forum\ForumThread;
use App\Services\Forum\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ModerationService $moderation) {}

    /** POST /forum/reports */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'in:thread,post'],
            'target_uuid' => ['required', 'uuid'],
            'reason' => ['required', 'in:' . implode(',', ForumReport::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $target = $data['target_type'] === 'thread'
            ? ForumThread::where('uuid', $data['target_uuid'])->firstOrFail()
            : ForumPost::where('uuid', $data['target_uuid'])->firstOrFail();

        try {
            $report = $this->moderation->report(
                $target, $request->user(), $data['reason'], $data['note'] ?? null,
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success(['uuid' => $report->uuid], 'Report submitted', 201);
    }

    /** GET /forum/reports — moderator queue */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasAnyRole(['system_admin', 'trainer', 'facilitator'])) {
            return $this->error('Forbidden', 403);
        }
        $status = $request->query('status', 'open');
        $reports = ForumReport::where('status', $status)
            ->with(['reporter:id,uuid,email', 'reporter.profile:user_id,full_name'])
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        return $this->success([
            'data' => $reports->getCollection()->map(fn (ForumReport $r) => [
                'uuid' => $r->uuid,
                'target_type' => $r->reportable_type,
                'target_id' => $r->reportable_id,
                'reason' => $r->reason,
                'note' => $r->note,
                'status' => $r->status,
                'reporter' => [
                    'name' => $r->reporter?->profile?->full_name ?? $r->reporter?->email,
                    'email' => $r->reporter?->email,
                ],
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    /** PATCH /forum/reports/{report} */
    public function update(ForumReport $report, Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:resolved,dismissed'],
            'resolution_note' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $this->moderation->resolve(
                $report, $request->user(), $data['status'], $data['resolution_note'] ?? null,
            );
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 403);
        }
        return $this->success(null, 'Report resolved');
    }
}
