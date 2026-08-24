<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS Module 13 — External professional certification.
 * Tracks expiry — a cert past expiry_date is flagged in the public profile.
 */
class TrainerCertification extends Model
{
    use SoftDeletes;

    const STATUS_PENDING  = 'pending';
    const STATUS_VERIFIED = 'verified';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid', 'trainer_profile_id',
        'name', 'issuer', 'credential_id', 'verification_url',
        'issue_date', 'expiry_date',
        'proof_file_path', 'proof_file_name', 'proof_file_size', 'proof_mime_type',
        'verification_status', 'verified_at', 'verified_by', 'rejection_reason',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'proof_file_size' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function getRouteKeyName(): string { return 'uuid'; }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (empty($c->uuid)) $c->uuid = (string) Str::uuid();
        });

        static::created(fn (self $c) =>
            app(\App\Services\TrainerPortal\VerificationService::class)->refreshBadgeState($c->profile));

        static::deleted(fn (self $c) =>
            $c->profile && app(\App\Services\TrainerPortal\VerificationService::class)->refreshBadgeState($c->profile));
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'trainer_profile_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::STATUS_VERIFIED;
    }

    /** True when the certification has a stored expiry that is in the past. */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /** True when the cert expires within the next 60 days. */
    public function isExpiringSoon(): bool
    {
        if ($this->expiry_date === null || !$this->expiry_date->isFuture()) {
            return false;
        }
        // Carbon 3 returns a signed diff; use abs to be defensive across versions.
        $days = abs((int) now()->diffInDays($this->expiry_date, false));
        return $days <= 60;
    }
}
