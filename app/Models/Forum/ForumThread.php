<?php

namespace App\Models\Forum;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ForumThread extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'category_id', 'author_id', 'course_id', 'assignment_id',
        'title', 'body', 'tags',
        'is_pinned', 'is_locked', 'is_hidden',
        'moderation_note', 'moderated_by', 'moderated_at',
        'accepted_post_id',
        'replies_count', 'votes_score', 'views_count', 'last_activity_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_pinned' => 'boolean',
        'is_locked' => 'boolean',
        'is_hidden' => 'boolean',
        'moderated_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'replies_count' => 'integer',
        'votes_score' => 'integer',
        'views_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $thread) {
            if (empty($thread->uuid)) $thread->uuid = (string) Str::uuid();
            if (empty($thread->last_activity_at)) $thread->last_activity_at = now();
        });
    }

    // Relationships ────────────────────────────────────────
    public function category(): BelongsTo
    {
        return $this->belongsTo(ForumCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'thread_id');
    }

    public function acceptedPost(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'accepted_post_id');
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(ForumSubscription::class, 'thread_id');
    }

    public function votes(): MorphMany
    {
        return $this->morphMany(ForumVote::class, 'votable', 'votable_type', 'votable_id')
            ->where('votable_type', 'thread');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(ForumReport::class, 'reportable', 'reportable_type', 'reportable_id')
            ->where('reportable_type', 'thread');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ForumAttachment::class, 'attachable', 'attachable_type', 'attachable_id')
            ->where('attachable_type', 'thread');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
