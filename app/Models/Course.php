<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS 4.2 Module 2 — Course.
 * Belongs to an instructor (trainer). Approved by system_admin before publishing.
 * Contains modules → lessons + quizzes.
 */
class Course extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'slug', 'title', 'description', 'category', 'level',
        'duration_hours', 'price_tzs',
        'thumbnail_url', 'instructor_id', 'created_by',
        'final_assessment_quiz_id',
        'status', 'rejection_reason', 'approved_by', 'approved_at',
        'published_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'price_tzs' => 'integer',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** True if the course has no price (null or 0 TZS). */
    public function isFree(): bool
    {
        return empty($this->price_tzs);
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (empty($c->uuid)) $c->uuid = (string) Str::uuid();
            if (empty($c->slug)) {
                $c->slug = Str::slug($c->title).'-'.Str::random(6);
            }
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function instructor(): BelongsTo { return $this->belongsTo(User::class, 'instructor_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('position');
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(Lesson::class, CourseModule::class);
    }

    public function enrollments(): HasMany { return $this->hasMany(Enrollment::class); }

    public function finalAssessment(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'final_assessment_quiz_id');
    }

    public function scopePublished($q) { return $q->where('status', 'published'); }
}
