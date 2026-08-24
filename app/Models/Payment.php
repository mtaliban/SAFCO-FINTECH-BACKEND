<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SRS Module 12 — Payment attempt.
 *
 * A single attempt at settling an invoice via a specific provider.
 * Idempotency: idempotency_key is UNIQUE — webhook retries won't create dupes.
 */
class Payment extends Model
{
    use HasFactory;

    const STATUS_PENDING   = 'pending';
    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_REVERSED  = 'reversed';

    const PROVIDER_MPESA        = 'mpesa';
    const PROVIDER_MIXX         = 'mixx';         // Mixx by Yas (formerly Tigo Pesa)
    const PROVIDER_AIRTEL_MONEY = 'airtel_money';
    const PROVIDER_CRDB         = 'crdb';
    const PROVIDER_NMB          = 'nmb';
    const PROVIDER_NBC          = 'nbc';
    const PROVIDER_CARD_VISA    = 'card_visa';
    const PROVIDER_CARD_MC      = 'card_mastercard';

    public const ALL_PROVIDERS = [
        self::PROVIDER_MPESA, self::PROVIDER_MIXX, self::PROVIDER_AIRTEL_MONEY,
        self::PROVIDER_CRDB, self::PROVIDER_NMB, self::PROVIDER_NBC,
        self::PROVIDER_CARD_VISA, self::PROVIDER_CARD_MC,
    ];

    protected $fillable = [
        'uuid', 'invoice_id', 'user_id', 'provider', 'provider_ref',
        'idempotency_key', 'msisdn', 'card_last4', 'card_brand', 'bank_account_hash',
        'amount_tzs', 'currency', 'status', 'failure_code', 'failure_message',
        'initiated_at', 'completed_at', 'expires_at', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'amount_tzs' => 'integer',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED, self::STATUS_FAILED,
            self::STATUS_CANCELLED, self::STATUS_EXPIRED, self::STATUS_REVERSED,
        ], true);
    }
}
