<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS 4.2 Lesson contains: Video · PDF Notes · Assignments (later).
 */
class Lesson extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'course_module_id', 'title', 'description', 'content',
        'video_url', 'pdf_url', 'duration_seconds', 'position',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $l) {
            if (empty($l->uuid)) $l->uuid = (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function module(): BelongsTo { return $this->belongsTo(CourseModule::class, 'course_module_id'); }

    public function completions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lesson_completions')
                    ->withTimestamps()
                    ->withPivot('completed_at');
    }

    public function assignments(): HasMany { return $this->hasMany(Assignment::class); }
    public function materials(): HasMany { return $this->hasMany(LessonMaterial::class)->orderBy('position'); }
}
