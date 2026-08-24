<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SRS Module 12 — append-only audit log of every provider callback.
 * Never mutate; use only for reconciliation & forensic replay.
 */
class PaymentEvent extends Model
{
    const TYPE_CALLBACK       = 'callback';
    const TYPE_WEBHOOK        = 'webhook';
    const TYPE_STATUS_QUERY   = 'status_query';
    const TYPE_REFUND_REQUEST = 'refund_request';

    protected $fillable = [
        'payment_id', 'provider', 'event_type',
        'payload', 'signature_verified', 'source_ip',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_verified' => 'boolean',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
