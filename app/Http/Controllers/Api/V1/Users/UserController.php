<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only endpoints for managing users across the platform.
 */
class UserController extends Controller
{
    /**
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()->with(['profile', 'organization', 'roles']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhereHas('profile', fn ($p) => $p->where('full_name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($r) => $r->where('name', $role));
        }

        $users = $query->latest()->paginate($request->query('per_page', 20));

        return $this->success(UserResource::collection($users)->response()->getData(true));
    }

    /**
     * GET /api/v1/admin/users/{uuid}
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);
        return $this->success(new UserResource($user->load(['profile', 'organization', 'roles', 'permissions'])));
    }

    /**
     * PATCH /api/v1/admin/users/{uuid}/status
     * Suspend / activate an account.
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'status' => ['required', 'in:active,inactive,suspended,pending'],
        ]);

        $user->update($data);

        return $this->success(new UserResource($user->fresh()), 'User status updated');
    }

    /**
     * DELETE /api/v1/admin/users/{uuid}
     */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);
        $user->delete();

        return $this->success(null, 'User archived');
    }
}
