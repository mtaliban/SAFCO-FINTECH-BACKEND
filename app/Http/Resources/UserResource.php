<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified' => ! is_null($this->email_verified_at),
            'phone' => $this->phone,
            'phone_verified' => ! is_null($this->phone_verified_at),
            'status' => $this->status,
            'auth_provider' => $this->auth_provider,
            'two_factor' => [
                'enabled' => $this->two_factor_enabled,
                'method' => $this->two_factor_method,
            ],
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->getAllPermissions()->pluck('name')),
            'profile' => new UserProfileResource($this->whenLoaded('profile')),
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
