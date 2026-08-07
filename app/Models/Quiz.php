<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Quiz extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'question_bank_id', 'created_by', 'name', 'slug', 'description',
        'cover_image', 'mode', 'category', 'difficulty', 'duration_minutes',
        'number_of_questions', 'passing_mark_percentage', 'max_attempts',
        'default_time_per_question', 'shuffle_questions', 'shuffle_options',
        'show_correct_after_each', 'show_leaderboard', 'award_bonus_for_speed',
        'allow_late_join', 'status', 'published_at', 'settings',
        'total_plays', 'avg_score',
    ];

    protected function casts(): array
    {
        return [
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'show_correct_after_each' => 'boolean',
            'show_leaderboard' => 'boolean',
            'award_bonus_for_speed' => 'boolean',
            'allow_late_join' => 'boolean',
            'published_at' => 'datetime',
            'settings' => 'array',
            'avg_score' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $q) {
            if (empty($q->uuid)) $q->uuid = (string) Str::uuid();
            if (empty($q->slug)) $q->slug = Str::slug($q->name) . '-' . Str::random(6);
        });
    }

    public function bank(): BelongsTo { return $this->belongsTo(QuestionBank::class, 'question_bank_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'quiz_questions')
                    ->withPivot(['position', 'override_time_seconds', 'override_points'])
                    ->withTimestamps()
                    ->orderBy('quiz_questions.position');
    }

    public function sessions(): HasMany { return $this->hasMany(QuizSession::class); }
    public function attempts(): HasMany { return $this->hasMany(QuizAttempt::class); }

    public function isPublished(): bool { return $this->status === 'published'; }
    public function isLiveKahoot(): bool { return $this->mode === 'live_kahoot'; }

    public function getRouteKeyName(): string { return 'uuid'; }
}
