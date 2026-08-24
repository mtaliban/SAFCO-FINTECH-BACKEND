<?php

namespace App\Models\Forum;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class ForumCategory extends Model
{
    use HasFactory;

    /** Cache key used by ForumController::categories — kept here so writes can bust it. */
    public const LIST_CACHE_KEY = 'forum:categories:v1';

    protected $fillable = [
        'slug', 'name', 'description', 'icon', 'color',
        'supports_accepted_answer', 'requires_course_context', 'sort_order',
    ];

    protected $casts = [
        'supports_accepted_answer' => 'boolean',
        'requires_course_context' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // NFR AUDIT-E: bust the categories cache on ANY write so the public
        // endpoint doesn't serve stale data for up to 5 minutes.
        static::saved(fn () => Cache::forget(self::LIST_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::LIST_CACHE_KEY));
    }

    public function threads(): HasMany
    {
        return $this->hasMany(ForumThread::class, 'category_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
