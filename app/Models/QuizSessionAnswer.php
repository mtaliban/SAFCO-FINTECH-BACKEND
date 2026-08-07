<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizSessionAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id', 'participant_id', 'question_id',
        'answer', 'is_correct', 'points_earned',
        'speed_bonus', 'streak_bonus',
        'response_time_ms', 'answered_at_position',
    ];

    protected function casts(): array
    {
        return [
            'answer' => 'array',
            'is_correct' => 'boolean',
        ];
    }

    public function session(): BelongsTo { return $this->belongsTo(QuizSession::class, 'session_id'); }
    public function participant(): BelongsTo { return $this->belongsTo(QuizSessionParticipant::class, 'participant_id'); }
    public function question(): BelongsTo { return $this->belongsTo(Question::class); }
}
