<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AssignmentSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'assignment_id', 'student_id', 'answer_text',
        'file_url', 'file_name', 'file_size', 'mime_type',
        'submitted_at', 'status', 'grade', 'feedback', 'graded_by', 'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $s) {
            if (empty($s->uuid)) $s->uuid = (string) Str::uuid();
            if (empty($s->submitted_at)) $s->submitted_at = now();
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function assignment(): BelongsTo { return $this->belongsTo(Assignment::class); }
    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
    public function grader(): BelongsTo { return $this->belongsTo(User::class, 'graded_by'); }
}
