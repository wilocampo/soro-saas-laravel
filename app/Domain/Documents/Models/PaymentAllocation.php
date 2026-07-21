<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies part of a payment to one document. Unapplied cash is an advance
 * (docs/specs/02 §4.2) — the allocation model is what lets the cash-basis
 * rule re-derive revenue/VAT from each invoice's stored snapshot.
 */
class PaymentAllocation extends Model
{
    protected $table = 'payment_allocations';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['applied_centavos' => 'integer'];

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
