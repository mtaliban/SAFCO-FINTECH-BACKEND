<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS 4.2: Course → Modules → Lessons + Quiz.
 * A course module groups related lessons (and optionally a knowledge-check quiz).
 */
class CourseModule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['uuid', 'course_id', 'title', 'description', 'position'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->uuid)) $m->uuid = (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function lessons(): HasMany { return $this->hasMany(Lesson::class)->orderBy('position'); }
    public function quizzes(): HasMany { return $this->hasMany(Quiz::class, 'course_module_id'); }
}
