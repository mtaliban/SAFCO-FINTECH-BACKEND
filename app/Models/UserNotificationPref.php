<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPref extends Model
{
    protected $fillable = ['user_id', 'event_key', 'channel', 'enabled'];
    protected $casts = ['enabled' => 'boolean'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
