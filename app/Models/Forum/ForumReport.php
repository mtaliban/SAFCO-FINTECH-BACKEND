<?php

namespace App\Models\Forum;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ForumReport extends Model
{
    protected $fillable = [
        'uuid', 'reporter_id', 'reportable_type', 'reportable_id',
        'reason', 'note', 'status', 'resolved_by', 'resolved_at', 'resolution_note',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

    public const REASONS = ['spam', 'offensive', 'off_topic', 'other'];
    public const STATUSES = ['open', 'resolved', 'dismissed'];

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            if (empty($r->uuid)) $r->uuid = (string) Str::uuid();
        });
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
