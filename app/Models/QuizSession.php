<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class QuizSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'pin', 'quiz_id', 'host_id', 'mode', 'status',
        'current_question_index', 'current_question_id',
        'current_question_started_at', 'current_question_ends_at',
        'participant_count', 'total_questions',
        'started_at', 'ended_at', 'settings', 'final_leaderboard',
    ];

    protected function casts(): array
    {
        return [
            'current_question_started_at' => 'datetime',
            'current_question_ends_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'settings' => 'array',
            'final_leaderboard' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            if (empty($s->uuid)) $s->uuid = (string) Str::uuid();
        });
    }

    public function quiz(): BelongsTo { return $this->belongsTo(Quiz::class); }
    public function host(): BelongsTo { return $this->belongsTo(User::class, 'host_id'); }
    public function currentQuestion(): BelongsTo { return $this->belongsTo(Question::class, 'current_question_id'); }

    public function participants(): HasMany { return $this->hasMany(QuizSessionParticipant::class, 'session_id'); }
    public function answers(): HasMany { return $this->hasMany(QuizSessionAnswer::class, 'session_id'); }

    public function isActive(): bool
    {
        return in_array($this->status, ['waiting', 'starting', 'question_active', 'question_ended', 'showing_leaderboard'], true);
    }

    /** MQTT / WebSocket topic for realtime updates. */
    public function realtimeTopic(): string
    {
        return "safco/lms/quiz/session/{$this->pin}";
    }

    public function getRouteKeyName(): string { return 'uuid'; }
}
