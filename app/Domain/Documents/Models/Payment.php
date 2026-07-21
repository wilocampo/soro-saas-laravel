<?php

namespace App\Domain\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A collection (received) or disbursement (paid). Under cash basis this is
 * where revenue/expense recognition happens, expanding each allocated
 * document's stored tax snapshot (docs/specs/02 §4.2).
 */
class Payment extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Deposit slip, bank confirmation, the customer's 2307. */
    public const RECEIPTS = 'receipts';

    protected $table = 'payments';

    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date',
        'amount_centavos' => 'integer',
        'ewt_centavos' => 'integer',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::RECEIPTS)
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic']);
    }

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
