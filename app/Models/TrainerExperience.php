<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS Module 13 — Trainer work experience.
 * end_date is null when the role is currently ongoing.
 */
class TrainerExperience extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'trainer_profile_id',
        'title', 'company', 'location',
        'start_date', 'end_date', 'description',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function getRouteKeyName(): string { return 'uuid'; }

    protected static function booted(): void
    {
        static::creating(function (self $e) {
            if (empty($e->uuid)) $e->uuid = (string) Str::uuid();
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'trainer_profile_id');
    }

    public function isCurrent(): bool
    {
        return $this->end_date === null;
    }

    public function durationYears(): float
    {
        $end = $this->end_date ?? now();
        return round($this->start_date->diffInDays($end) / 365.25, 1);
    }
}
