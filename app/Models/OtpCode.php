<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'identifier',
        'code',
        'type',
        'channel',
        'attempts',
        'expires_at',
        'verified_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return ! is_null($this->verified_at);
    }

    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= config('auth.otp.max_attempts', 3);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('verified_at')
                     ->where('expires_at', '>', now());
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
