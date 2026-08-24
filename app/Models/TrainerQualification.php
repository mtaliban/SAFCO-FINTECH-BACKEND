<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS Module 13 — Education / academic qualification.
 */
class TrainerQualification extends Model
{
    use SoftDeletes;

    const STATUS_PENDING  = 'pending';
    const STATUS_VERIFIED = 'verified';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid', 'trainer_profile_id',
        'institution', 'degree', 'field_of_study',
        'start_year', 'end_year',
        'proof_file_path', 'proof_file_name', 'proof_file_size', 'proof_mime_type',
        'verification_status', 'verified_at', 'verified_by', 'rejection_reason',
    ];

    protected $casts = [
        'start_year' => 'integer',
        'end_year' => 'integer',
        'proof_file_size' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function getRouteKeyName(): string { return 'uuid'; }

    protected static function booted(): void
    {
        static::creating(function (self $q) {
            if (empty($q->uuid)) $q->uuid = (string) Str::uuid();
        });

        // Adding a new pending item may invalidate the trainer's badge.
        static::created(fn (self $q) =>
            app(\App\Services\TrainerPortal\VerificationService::class)->refreshBadgeState($q->profile));

        // Deleting a verified item may drop trainer below the "fully verified" bar.
        static::deleted(fn (self $q) =>
            $q->profile && app(\App\Services\TrainerPortal\VerificationService::class)->refreshBadgeState($q->profile));
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
}
