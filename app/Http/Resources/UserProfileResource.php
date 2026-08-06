<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            'position' => $this->position,
            'department' => $this->department,
            'bio' => $this->bio,
            'profile_picture' => $this->profile_picture_url,
            'profile_picture_thumbnail' => $this->profile_picture_thumbnail_url,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->city,
                'region' => $this->region,
                'postal_code' => $this->postal_code,
                'country' => $this->country,
            ],
            'social_links' => $this->social_links,
            'preferences' => $this->preferences,
            'completion_percentage' => $this->profile_completion_percentage,
            'is_complete' => ! is_null($this->profile_completed_at),
        ];
    }
}
