<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * System-wide audit log for the System Administrator (SRS 3.1).
 * Shows EVERY user's login events — IP, device, user-agent, success/failure.
 */
class AuditLogController extends Controller
{
    /** GET /api/v1/admin/audit-log */
    public function index(Request $request): JsonResponse
    {
        $q = LoginHistory::query()
            ->with(['user:id,uuid,email'])
            ->latest();

        if ($email = $request->query('email')) {
            $q->whereHas('user', fn ($u) => $u->where('email', 'like', "%{$email}%"));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($ip = $request->query('ip')) {
            $q->where('ip_address', $ip);
        }

        $page = $q->paginate((int) $request->query('per_page', 25));

        return $this->success([
            'data' => $page->getCollection()->map(fn ($h) => [
                'id' => $h->id,
                'user' => [
                    'uuid' => $h->user?->uuid,
                    'email' => $h->user?->email,
                ],
                'ip_address' => $h->ip_address,
                'user_agent' => $h->user_agent,
                'device_type' => $h->device_type,
                'location' => $h->location,
                'status' => $h->status,
                'created_at' => $h->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
