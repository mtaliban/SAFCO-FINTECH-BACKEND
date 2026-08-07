<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttemptAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id', 'question_id', 'answer', 'is_correct',
        'points_earned', 'response_time_ms',
    ];

    protected function casts(): array
    {
        return ['answer' => 'array', 'is_correct' => 'boolean'];
    }

    public function attempt(): BelongsTo { return $this->belongsTo(QuizAttempt::class, 'attempt_id'); }
    public function question(): BelongsTo { return $this->belongsTo(Question::class); }
}
