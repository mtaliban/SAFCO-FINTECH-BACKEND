<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class QuestionBank extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'owner_id', 'name', 'slug', 'description', 'category',
        'difficulty', 'is_public', 'is_active', 'total_questions', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $bank) {
            if (empty($bank->uuid)) $bank->uuid = (string) Str::uuid();
            if (empty($bank->slug)) $bank->slug = Str::slug($bank->name) . '-' . Str::random(6);
        });
    }

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function questions(): HasMany { return $this->hasMany(Question::class); }
    public function quizzes(): HasMany { return $this->hasMany(Quiz::class); }

    public function getRouteKeyName(): string { return 'uuid'; }
}
