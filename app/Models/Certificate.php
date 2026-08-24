<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * SRS Module 10 — Certificate.
 *
 * Issued when a student:
 *   - passes a Quiz in `exam_type = final_certification` mode, OR
 *   - completes 100% of a course whose final_assessment_quiz was also passed.
 *
 * Immutable snapshot: student_name_snapshot + course_title_snapshot are frozen
 * at issue time so historical certificates keep their names regardless of later edits.
 */
class Certificate extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_REVOKED  = 'revoked';
    public const STATUS_EXPIRED  = 'expired';

    protected $fillable = [
        'uuid', 'cert_number',
        'user_id', 'course_id', 'quiz_attempt_id',
        'student_name_snapshot', 'course_title_snapshot',
        'completion_date', 'issued_at', 'score_percentage',
        'verification_hash', 'status',
        'revoked_at', 'revoked_reason', 'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'completion_date' => 'date',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'score_percentage' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (empty($c->uuid)) $c->uuid = (string) Str::uuid();
            if (empty($c->issued_at)) $c->issued_at = now();
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    /* ---------------- Relationships ---------------- */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function attempt(): BelongsTo { return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id'); }
    public function revoker(): BelongsTo { return $this->belongsTo(User::class, 'revoked_by'); }

    /* ---------------- Helpers ---------------- */
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isRevoked(): bool { return $this->status === self::STATUS_REVOKED; }
}
