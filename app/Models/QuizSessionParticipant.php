<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class QuizSessionParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'session_id', 'user_id', 'nickname', 'avatar_url', 'team_name',
        'total_score', 'current_rank', 'correct_answers', 'incorrect_answers',
        'longest_streak', 'current_streak', 'avg_response_time_ms',
        'socket_id', 'ip_address', 'device_type',
        'is_connected', 'last_seen_at', 'joined_at', 'left_at',
    ];

    protected function casts(): array
    {
        return [
            'is_connected' => 'boolean',
            'last_seen_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            if (empty($p->uuid)) $p->uuid = (string) Str::uuid();
            if (empty($p->joined_at)) $p->joined_at = now();
        });
    }

    public function session(): BelongsTo { return $this->belongsTo(QuizSession::class, 'session_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function answers(): HasMany { return $this->hasMany(QuizSessionAnswer::class, 'participant_id'); }

    public function getRouteKeyName(): string { return 'uuid'; }
}
