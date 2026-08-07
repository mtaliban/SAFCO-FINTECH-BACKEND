<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Question extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = [
        'multiple_choice',
        'true_false',
        'multiple_select',
        'fill_in_blank',
        'matching',
        'short_answer',
    ];

    /** Kahoot-style option colors + shapes for multiple choice. */
    public const OPTION_STYLES = [
        ['color' => '#e21b3c', 'shape' => 'triangle'],
        ['color' => '#1368ce', 'shape' => 'diamond'],
        ['color' => '#d89e00', 'shape' => 'circle'],
        ['color' => '#26890c', 'shape' => 'square'],
    ];

    protected $fillable = [
        'uuid', 'question_bank_id', 'created_by', 'type', 'text', 'explanation',
        'image_url', 'audio_url', 'video_url', 'options', 'correct_answer',
        'points', 'time_limit_seconds', 'difficulty', 'tags', 'metadata',
        'is_active', 'times_used', 'avg_correct_rate',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_answer' => 'array',
            'tags' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'avg_correct_rate' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $q) {
            if (empty($q->uuid)) $q->uuid = (string) Str::uuid();
        });
    }

    public function bank(): BelongsTo { return $this->belongsTo(QuestionBank::class, 'question_bank_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function quizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'quiz_questions')
                    ->withPivot(['position', 'override_time_seconds', 'override_points'])
                    ->withTimestamps();
    }

    /**
     * Check if a submitted answer is correct.
     * Handles all six question types.
     */
    public function checkAnswer(mixed $submitted): bool
    {
        $correct = $this->correct_answer;

        return match ($this->type) {
            'multiple_choice', 'fill_in_blank' => $this->normalize($submitted) === $this->normalize($correct),
            'true_false' => (bool) $submitted === (bool) $correct,
            'multiple_select' => $this->arraysMatch((array) $submitted, (array) $correct),
            'matching' => $this->matchingEquals((array) $submitted, (array) $correct),
            'short_answer' => false, // manual grading
            default => false,
        };
    }

    protected function normalize(mixed $v): string
    {
        if (is_array($v)) $v = $v[0] ?? '';
        return strtolower(trim((string) $v));
    }

    protected function arraysMatch(array $a, array $b): bool
    {
        $a = array_map(fn ($x) => strtolower((string) $x), $a);
        $b = array_map(fn ($x) => strtolower((string) $x), $b);
        sort($a);
        sort($b);
        return $a === $b;
    }

    protected function matchingEquals(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);
        foreach ($b as $k => $v) {
            if (($a[$k] ?? null) !== $v) return false;
        }
        return true;
    }

    public function getRouteKeyName(): string { return 'uuid'; }
}
