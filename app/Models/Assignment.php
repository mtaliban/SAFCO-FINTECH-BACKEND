<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SRS 4.2 Lesson Contains Assignments.
 * Students submit → Trainer grades.
 */
class Assignment extends Model
{
    use HasFactory, SoftDeletes;

    /** SRS Module 9 — file types students may upload as their answer. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'docx', 'doc', 'zip'];
    public const ALLOWED_MIMES = [
        'pdf'  => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
        'zip'  => 'application/zip',
    ];
    public const MAX_UPLOAD_KB = 25 * 1024; // 25 MB

    protected $fillable = [
        'uuid', 'lesson_id', 'title', 'instructions',
        'brief_file_url', 'brief_file_name', 'brief_file_size', 'brief_mime_type',
        'max_points', 'due_date', 'allowed_file_types',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
            'allowed_file_types' => 'array',
            'brief_file_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            if (empty($a->uuid)) $a->uuid = (string) Str::uuid();
        });

        // Purge brief + all submission files when an assignment is force-deleted
        // (softDeletes fires 'deleting' too — but Storage::delete on a missing file is a no-op)
        static::deleting(function (self $a) {
            $disk = Storage::disk('local');
            if ($a->brief_file_url) $disk->delete($a->brief_file_url);
            foreach ($a->submissions()->cursor() as $s) {
                if ($s->file_url) $disk->delete($s->file_url);
            }
            // Best-effort: try to remove the empty per-assignment directory too
            $disk->deleteDirectory("assignments/{$a->uuid}");
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class); }
    public function submissions(): HasMany { return $this->hasMany(AssignmentSubmission::class); }
}
