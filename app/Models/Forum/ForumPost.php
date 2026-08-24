<?php

namespace App\Models\Forum;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ForumPost extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'thread_id', 'author_id', 'parent_post_id',
        'body', 'votes_score', 'is_accepted_answer', 'is_hidden',
        'moderation_note', 'moderated_by', 'moderated_at',
        'edited_at', 'edited_by',
    ];

    protected $casts = [
        'is_accepted_answer' => 'boolean',
        'is_hidden' => 'boolean',
        'moderated_at' => 'datetime',
        'edited_at' => 'datetime',
        'votes_score' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $post) {
            if (empty($post->uuid)) $post->uuid = (string) Str::uuid();
        });

        // Keep thread.replies_count + last_activity_at in sync.
        static::created(function (self $post) {
            $thread = $post->thread;
            if (!$thread) return;
            $thread->increment('replies_count');
            $thread->forceFill(['last_activity_at' => now()])->save();
        });

        static::deleted(function (self $post) {
            $thread = $post->thread;
            if (!$thread) return;
            // Recount from source of truth — decrement can drift under concurrency.
            $thread->forceFill([
                'replies_count' => self::where('thread_id', $thread->id)->count(),
            ])->save();
            // If the deleted post WAS the accepted answer, clear it.
            if ($thread->accepted_post_id === $post->id) {
                $thread->forceFill(['accepted_post_id' => null])->save();
            }
        });
    }

    // Relationships ────────────────────────────────────────
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ForumThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_post_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_post_id');
    }

    public function votes(): MorphMany
    {
        return $this->morphMany(ForumVote::class, 'votable', 'votable_type', 'votable_id')
            ->where('votable_type', 'post');
    }

    public function reports(): MorphMany
    {
        return $this->morphMany(ForumReport::class, 'reportable', 'reportable_type', 'reportable_id')
            ->where('reportable_type', 'post');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ForumAttachment::class, 'attachable', 'attachable_type', 'attachable_id')
            ->where('attachable_type', 'post');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
