<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A collection (received) or disbursement (paid). Under cash basis this is
 * where revenue/expense recognition happens, expanding each allocated
 * document's stored tax snapshot (docs/specs/02 §4.2).
 */
class Payment extends Model
{
    protected $table = 'payments';

    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date',
        'amount_centavos' => 'integer',
        'ewt_centavos' => 'integer',
    ];

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** Cash + tax withheld = what the document was settled for. */
    public function settledCentavos(): int
    {
        return $this->amount_centavos + $this->ewt_centavos;
    }

    /** Cash not applied to any document is an advance / customer deposit. */
    public function unappliedCentavos(): int
    {
        return $this->settledCentavos() - (int) $this->allocations()->sum('applied_centavos');
    }
}
