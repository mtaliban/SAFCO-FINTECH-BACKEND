<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SRS Module 12 — Invoice.
 *
 * Canonical bill. All amounts stored as INTEGER TZS whole shillings.
 * Status lifecycle: draft → issued → paid | void | refunded | partially_refunded.
 */
class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_DRAFT               = 'draft';
    const STATUS_ISSUED              = 'issued';
    const STATUS_PAID                = 'paid';
    const STATUS_VOID                = 'void';
    const STATUS_REFUNDED            = 'refunded';
    const STATUS_PARTIALLY_REFUNDED  = 'partially_refunded';

    protected $fillable = [
        'uuid', 'invoice_number', 'billed_user_id', 'billed_org_id',
        'subject_type', 'subject_id',
        'subtotal_tzs', 'tax_tzs', 'total_tzs', 'currency',
        'status', 'description', 'line_items', 'meta',
        'issued_at', 'due_at', 'paid_at', 'voided_at',
    ];

    protected $casts = [
        'line_items' => 'array',
        'meta' => 'array',
        'subtotal_tzs' => 'integer',
        'tax_tzs' => 'integer',
        'total_tzs' => 'integer',
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function billedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billed_user_id');
    }

    public function billedOrg(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'billed_org_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function successfulPayment(): ?Payment
    {
        return $this->payments()->where('status', Payment::STATUS_SUCCEEDED)->first();
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_REFUNDED], true);
    }

    /** Sum of refunds already issued, in TZS. */
    public function refundedAmount(): int
    {
        return (int) $this->refunds()->where('status', Refund::STATUS_SUCCEEDED)->sum('amount_tzs');
    }
}
