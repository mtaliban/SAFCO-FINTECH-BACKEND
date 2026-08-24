<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS Module 13 — Trainer public/professional profile.
 * 1:1 with User; extends the generic UserProfile with trainer-only fields.
 */
class TrainerProfile extends Model
{
    use HasFactory, SoftDeletes;

    const AVAILABILITY_AVAILABLE   = 'available';
    const AVAILABILITY_BUSY        = 'busy';
    const AVAILABILITY_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'uuid', 'user_id', 'public_slug',
        'headline', 'bio_long', 'years_experience',
        'expertise_areas', 'teaching_languages', 'timezone', 'hourly_rate_tzs',
        'availability_status', 'is_public',
        'is_verified', 'verified_at', 'verified_by',
        'rating_avg', 'rating_count', 'students_taught_count',
        'accepts_direct_inquiries', 'public_email',
    ];

    protected $casts = [
        'expertise_areas' => 'array',
        'teaching_languages' => 'array',
        'years_experience' => 'integer',
        'hourly_rate_tzs' => 'integer',
        'is_public' => 'boolean',
        'is_verified' => 'boolean',
        'accepts_direct_inquiries' => 'boolean',
        'rating_avg' => 'decimal:2',
        'rating_count' => 'integer',
        'students_taught_count' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_slug';
    }

    protected static function booted(): void
    {
        static::creating(function (self $tp) {
            if (empty($tp->uuid)) $tp->uuid = (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(TrainerQualification::class)->orderByDesc('end_year');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(TrainerCertification::class)->orderByDesc('issue_date');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(TrainerExperience::class)->orderByDesc('start_date');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TrainerReview::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'instructor_id', 'user_id');
    }

    /**
     * Compute years of experience from the earliest experience entry.
     * Used when the trainer hasn't manually set years_experience.
     * Returns 0 when no experience rows exist.
     */
    public function derivedYearsExperience(): int
    {
        $earliest = $this->experiences()->min('start_date');
        if (!$earliest) return 0;
        // Use calendar-year semantics (leap-year aware) instead of days/365.25,
        // so subYears(5) test data returns exactly 5.
        return (int) abs((int) \Carbon\Carbon::parse($earliest)->diffInYears(now(), false));
    }

    /** Public accessor: manual value if set, else derived from experience rows. */
    public function effectiveYearsExperience(): ?int
    {
        if ($this->years_experience !== null && $this->years_experience > 0) {
            return $this->years_experience;
        }
        $derived = $this->derivedYearsExperience();
        return $derived > 0 ? $derived : null;
    }

    /** Returns true when EVERY qualification + certification is verified. */
    public function isFullyVerified(): bool
    {
        $qualsPending = $this->qualifications()
            ->whereIn('verification_status', ['pending', 'rejected'])->count();
        if ($qualsPending > 0) return false;

        $certsPending = $this->certifications()
            ->whereIn('verification_status', ['pending', 'rejected'])->count();
        return $certsPending === 0
            && ($this->qualifications()->count() > 0 || $this->certifications()->count() > 0);
    }
}
