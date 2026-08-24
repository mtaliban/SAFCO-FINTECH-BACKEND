<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'event_key', 'channel',
        'subject', 'preview', 'payload',
        'status', 'skipped_reason', 'error_message',
        'attempts', 'sent_at', 'failed_at', 'next_retry_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            if (empty($d->uuid)) $d->uuid = (string) Str::uuid();
        });
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
