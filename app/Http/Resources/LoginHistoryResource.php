<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'auth_method' => $this->auth_method,
            'ip_address' => $this->ip_address,
            'device_type' => $this->device_type,
            'device_name' => $this->device_name,
            'browser' => $this->browser,
            'os' => $this->os,
            'country' => $this->country,
            'city' => $this->city,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
