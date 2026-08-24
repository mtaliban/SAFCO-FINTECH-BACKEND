<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * SRS Module 3 — supports every listed content type.
 */
class LessonMaterial extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = [
        'document_pdf', 'document_word', 'document_excel', 'document_powerpoint',
        'video_mp4', 'video_youtube', 'video_vimeo',
        'interactive_scorm', 'interactive_html5',
    ];

    public const CATEGORIES = [
        'documents' => ['document_pdf', 'document_word', 'document_excel', 'document_powerpoint'],
        'videos' => ['video_mp4', 'video_youtube', 'video_vimeo'],
        'interactive' => ['interactive_scorm', 'interactive_html5'],
    ];

    protected $fillable = [
        'uuid', 'lesson_id', 'type', 'title', 'description',
        'url', 'mime_type', 'file_size', 'position', 'metadata',
        // Async processing state (populated by ProcessMaterialUpload job)
        'processing_status', 'processing_progress', 'processing_error',
        'thumbnail_url', 'duration_seconds', 'width', 'height', 'page_count',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'file_size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->uuid)) $m->uuid = (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class); }

    public function getCategoryAttribute(): string
    {
        foreach (self::CATEGORIES as $cat => $types) {
            if (in_array($this->type, $types, true)) return $cat;
        }
        return 'other';
    }
}
