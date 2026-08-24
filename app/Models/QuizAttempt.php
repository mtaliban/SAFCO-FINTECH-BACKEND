<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'quiz_id', 'user_id', 'attempt_number', 'status', 'exam_type',
        'total_questions', 'correct_answers', 'incorrect_answers', 'unanswered',
        'total_score', 'max_possible_score', 'percentage', 'passed',
        'started_at', 'expires_at', 'completed_at', 'duration_seconds',
        'question_snapshot', 'violations', 'auto_submit_reason',
    ];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'percentage' => 'decimal:2',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'question_snapshot' => 'array',
            'violations' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'in_progress'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->status === 'in_progress'
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            if (empty($a->uuid)) $a->uuid = (string) Str::uuid();
            if (empty($a->started_at)) $a->started_at = now();
        });
    }

    public function quiz(): BelongsTo { return $this->belongsTo(Quiz::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function answers(): HasMany { return $this->hasMany(QuizAttemptAnswer::class, 'attempt_id'); }

    public function getRouteKeyName(): string { return 'uuid'; }
}
