<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS Module 4 — a scheduled attendance-taking event.
 * Statuses: scheduled → open → closed.
 * A QR token is rotated any time the trainer wants (to defeat screenshot cheating).
 */
class AttendanceSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'course_id', 'trainer_id', 'title', 'description', 'location',
        'starts_at', 'ends_at', 'late_threshold_minutes',
        'qr_token', 'qr_expires_at',
        'status', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'qr_expires_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            if (empty($s->uuid)) $s->uuid = (string) Str::uuid();
            if (empty($s->qr_token)) $s->qr_token = self::freshQrToken();
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function trainer(): BelongsTo { return $this->belongsTo(User::class, 'trainer_id'); }
    public function records(): HasMany { return $this->hasMany(AttendanceRecord::class); }

    public function scopeOpen($q) { return $q->where('status', 'open'); }

    public static function freshQrToken(): string
    {
        return bin2hex(random_bytes(24)); // 48-char hex
    }

    public function rotateQr(?int $expiresInMinutes = null): void
    {
        $this->qr_token = self::freshQrToken();
        $this->qr_expires_at = $expiresInMinutes ? now()->addMinutes($expiresInMinutes) : null;
        $this->save();
    }

    /** Determines status ('present' or 'late') based on check-in time. */
    public function classifyCheckIn(\DateTimeInterface $when): string
    {
        $threshold = $this->starts_at->copy()->addMinutes($this->late_threshold_minutes);
        return $when > $threshold ? 'late' : 'present';
    }

    /** Users who are expected to attend — enrolled in the linked course. */
    public function expectedStudentIds(): \Illuminate\Support\Collection
    {
        if (!$this->course_id) return collect();
        return Enrollment::where('course_id', $this->course_id)->pluck('user_id');
    }
}
