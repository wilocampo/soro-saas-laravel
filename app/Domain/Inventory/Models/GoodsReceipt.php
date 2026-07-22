<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Documents\Models\Partner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods received. Credits GRNI until the vendor's bill arrives and clears it
 * to A/P (docs/specs/08 §3, D15) — so the liability is recognised when the
 * goods land, not when the paperwork does.
 */
class GoodsReceipt extends Model
{
    protected $table = 'goods_receipts';

    protected $guarded = ['id'];

    protected $casts = [
        'received_date' => 'date',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_cost_centavos' => 'integer',
    ];

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function recalculateTotals(): void
    {
        $this->total_cost_centavos = (int) $this->lines()->sum('line_cost_centavos');
    }
}
