<?php

namespace App\Models\Forum;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ForumAttachment extends Model
{
    protected $fillable = [
        'uuid', 'attachable_type', 'attachable_id', 'uploaded_by',
        'file_path', 'file_name', 'mime_type', 'file_size',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            if (empty($a->uuid)) $a->uuid = (string) Str::uuid();
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
