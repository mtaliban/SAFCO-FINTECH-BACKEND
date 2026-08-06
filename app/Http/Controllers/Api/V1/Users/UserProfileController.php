<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Requests\User\UploadProfilePictureRequest;
use App\Http\Resources\UserProfileResource;
use App\Http\Resources\UserResource;
use App\Services\User\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function __construct(protected UserProfileService $profiles)
    {
    }

    /**
     * GET /api/v1/users/profile
     */
    public function show(Request $request): JsonResponse
    {
        return $this->success(
            new UserProfileResource($request->user()->profile)
        );
    }

    /**
     * PATCH /api/v1/users/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profiles->update($request->user(), $request->validated());

        return $this->success(
            new UserResource($user->load(['profile', 'organization'])),
            'Profile updated successfully'
        );
    }

    /**
     * POST /api/v1/users/profile/picture
     * Uploads a new profile picture. A background job generates the thumbnail.
     */
    public function uploadPicture(UploadProfilePictureRequest $request): JsonResponse
    {
        $user = $this->profiles->uploadProfilePicture(
            $request->user(),
            $request->file('picture')
        );

        return $this->success(
            new UserResource($user->load('profile')),
            'Profile picture uploaded. Thumbnail is being generated.'
        );
    }
}
