<?php

namespace App\Http\Controllers\Api\V1\Corporate;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Corporate Client — view + invite employees in own organization (SRS 3.4).
 */
class EmployeesController extends Controller
{
    /** GET /api/v1/corporate/my-employees */
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        if (!$orgId) {
            return $this->error('You are not attached to an organization.', 422);
        }

        $employees = User::with('profile')
            ->where('organization_id', $orgId)
            ->where('id', '!=', $request->user()->id)
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'data' => $employees->getCollection()->map(fn ($u) => [
                'uuid' => $u->uuid,
                'email' => $u->email,
                'full_name' => $u->profile?->full_name,
                'position' => $u->profile?->position,
                'status' => $u->status,
                'roles' => $u->roles->pluck('name'),
                'created_at' => $u->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    /** POST /api/v1/corporate/invite */
    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'full_name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
        ]);

        $orgId = $request->user()->organization_id;
        if (!$orgId) {
            return $this->error('You are not attached to an organization.', 422);
        }

        $tempPassword = 'Welcome@'.random_int(1000, 9999);

        $employee = User::create([
            'email' => $data['email'],
            'password' => Hash::make($tempPassword),
            'organization_id' => $orgId,
            'status' => 'pending',
        ]);

        $employee->profile()->create([
            'full_name' => $data['full_name'],
            'position' => $data['position'] ?? null,
        ]);

        $employee->syncRoles(['student']);

        return $this->success([
            'uuid' => $employee->uuid,
            'email' => $employee->email,
            'temp_password' => $tempPassword,
            'message' => 'Employee invited. Share the temporary password securely.',
        ], 'Employee invited', 201);
    }
}
