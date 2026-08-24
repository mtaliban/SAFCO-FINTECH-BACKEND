<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BroadcastAnnouncement extends Model
{
    protected $fillable = [
        'uuid', 'created_by', 'title', 'body', 'segment', 'channels',
        'audience_size', 'sent_count', 'failed_count', 'status', 'sent_at',
    ];

    protected $casts = [
        'segment' => 'array',
        'channels' => 'array',
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $b) {
            if (empty($b->uuid)) $b->uuid = (string) Str::uuid();
        });
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
