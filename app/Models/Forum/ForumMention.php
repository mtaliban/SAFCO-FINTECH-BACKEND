<?php

namespace App\Models\Forum;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForumMention extends Model
{
    protected $fillable = [
        'mentionable_type', 'mentionable_id', 'mentioned_user_id', 'notified_at',
    ];

    protected $casts = ['notified_at' => 'datetime'];

    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }
}
