<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /** GET /api/v1/admin/users */
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

    /** GET /api/v1/admin/users/{uuid} */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);
        return $this->success(new UserResource($user->load(['profile', 'organization', 'roles', 'permissions'])));
    }

    /** POST /api/v1/admin/users — Create a new user */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $data = $request->validate([
            'email'      => ['required', 'email', 'unique:users,email'],
            'password'   => ['required', Password::min(8)->mixedCase()->numbers()],
            'full_name'  => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'phone'      => ['nullable', 'string', 'max:20'],
            'role'       => ['required', 'in:student,trainer,corporate_client,system_admin'],
            'status'     => ['nullable', 'in:active,pending,suspended,inactive'],
            'position'   => ['nullable', 'string', 'max:255'],
            'gender'     => ['nullable', 'in:male,female,other,prefer_not_to_say'],
        ]);

        $nameParts = explode(' ', trim($data['full_name']), 2);

        $user = User::create([
            'uuid'               => (string) Str::uuid(),
            'email'              => $data['email'],
            'phone'              => $data['phone'] ?? null,
            'password'           => Hash::make($data['password']),
            'status'             => $data['status'] ?? 'active',
            'email_verified_at'  => now(),
        ]);

        UserProfile::create([
            'user_id'    => $user->id,
            'full_name'  => $data['full_name'],
            'first_name' => $data['first_name'] ?? $nameParts[0],
            'last_name'  => $data['last_name']  ?? ($nameParts[1] ?? ''),
            'position'   => $data['position'] ?? null,
            'gender'     => $data['gender'] ?? null,
        ]);

        $user->assignRole($data['role']);

        // Auto-provision a public TrainerProfile for new trainer accounts
        if ($data['role'] === 'trainer') {
            $name = $data['full_name'];
            $base = \Illuminate\Support\Str::slug($name ?: 'trainer');
            do {
                $slug = $base . '-' . \Illuminate\Support\Str::random(5);
            } while (TrainerProfile::where('public_slug', $slug)->exists());

            TrainerProfile::create([
                'user_id'             => $user->id,
                'public_slug'         => $slug,
                'is_public'           => true,
                'availability_status' => 'available',
                'expertise_areas'     => [],
                'teaching_languages'  => ['en'],
                'timezone'            => 'Africa/Nairobi',
            ]);
        }

        return $this->success(
            new UserResource($user->load(['profile', 'organization', 'roles'])),
            'User created successfully',
            201
        );
    }

    /** PUT /api/v1/admin/users/{uuid} — Update user details */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'email'      => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'phone'      => ['nullable', 'string', 'max:20'],
            'password'   => ['nullable', Password::min(8)->mixedCase()->numbers()],
            'full_name'  => ['sometimes', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'position'   => ['nullable', 'string', 'max:255'],
            'gender'     => ['nullable', 'in:male,female,other,prefer_not_to_say'],
            'role'       => ['nullable', 'in:student,trainer,corporate_client,system_admin'],
            'status'     => ['nullable', 'in:active,pending,suspended,inactive'],
        ]);

        // Update user core fields
        $userFields = array_filter([
            'email'  => $data['email']  ?? null,
            'phone'  => $data['phone']  ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null);

        if (!empty($data['password'])) {
            $userFields['password'] = Hash::make($data['password']);
        }

        if (!empty($userFields)) {
            $user->update($userFields);
        }

        // Update profile
        $profileFields = array_filter([
            'full_name'  => $data['full_name']  ?? null,
            'first_name' => $data['first_name'] ?? null,
            'last_name'  => $data['last_name']  ?? null,
            'position'   => $data['position']   ?? null,
            'gender'     => $data['gender']     ?? null,
        ], fn ($v) => $v !== null);

        if (!empty($profileFields)) {
            $user->profile()->updateOrCreate(['user_id' => $user->id], $profileFields);
        }

        // Change role if provided
        if (!empty($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return $this->success(
            new UserResource($user->fresh(['profile', 'organization', 'roles'])),
            'User updated successfully'
        );
    }

    /** PATCH /api/v1/admin/users/{uuid}/status */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'status' => ['required', 'in:active,inactive,suspended,pending'],
        ]);

        $user->update($data);

        return $this->success(new UserResource($user->fresh()), 'User status updated');
    }

    /** DELETE /api/v1/admin/users/{uuid} */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);
        $user->delete();

        return $this->success(null, 'User archived');
    }
}
