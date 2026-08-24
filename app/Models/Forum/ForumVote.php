<?php

namespace App\Models\Forum;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForumVote extends Model
{
    protected $fillable = ['user_id', 'votable_type', 'votable_id', 'value'];

    protected $casts = ['value' => 'integer'];

    public const TARGET_THREAD = 'thread';
    public const TARGET_POST = 'post';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
