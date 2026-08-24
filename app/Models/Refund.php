<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SRS Module 12 — Refund record.
 * Ties back to both the original Payment and the Invoice.
 */
class Refund extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'uuid', 'payment_id', 'invoice_id',
        'amount_tzs', 'reason',
        'requested_by', 'provider_ref',
        'status', 'requested_at', 'completed_at', 'failure_message',
    ];

    protected $casts = [
        'amount_tzs' => 'integer',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
