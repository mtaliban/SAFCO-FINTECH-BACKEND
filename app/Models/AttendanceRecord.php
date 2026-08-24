<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * SRS Module 4 — one check-in record per (session, student).
 */
class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'attendance_session_id', 'student_id',
        'status', 'method', 'checked_in_at', 'notes', 'marked_by',
    ];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            if (empty($r->uuid)) $r->uuid = (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function session(): BelongsTo { return $this->belongsTo(AttendanceSession::class, 'attendance_session_id'); }
    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
    public function marker(): BelongsTo { return $this->belongsTo(User::class, 'marked_by'); }
}
