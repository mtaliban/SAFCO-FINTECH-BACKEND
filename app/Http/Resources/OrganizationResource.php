<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'logo_url' => $this->logo_url,
            'country' => $this->country,
            'is_verified' => $this->is_verified,
        ];
    }
}
