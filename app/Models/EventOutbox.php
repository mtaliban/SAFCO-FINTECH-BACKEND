<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Transactional Outbox pattern - ensures reliable event publishing.
 * Events are written here in the same DB transaction as business logic,
 * then a background worker publishes to RabbitMQ + MQTT.
 */
class EventOutbox extends Model
{
    use HasFactory;

    protected $table = 'event_outbox';

    protected $fillable = [
        'event_id', 'event_name', 'aggregate_type', 'aggregate_id',
        'payload', 'routing_key', 'broker', 'status',
        'attempts', 'last_error', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            if (empty($event->event_id)) {
                $event->event_id = (string) Str::uuid();
            }
        });
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function markPublished(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->increment('attempts');
        $this->update([
            'status' => $this->attempts >= 5 ? 'failed' : 'pending',
            'last_error' => $error,
        ]);
    }
}
