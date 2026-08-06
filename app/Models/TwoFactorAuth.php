<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class TwoFactorAuth extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'method',
        'secret',
        'recovery_codes',
        'enabled_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'recovery_codes' => 'array',
            'enabled_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setSecretAttribute(?string $value): void
    {
        $this->attributes['secret'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getSecretAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }
}
