<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock adjustment: shrinkage, spoilage, damage, found stock, a keying
 * correction, or the outcome of a physical count (docs/specs/08 §3).
 */
class StockAdjustment extends Model
{
    protected $table = 'stock_adjustments';

    protected $guarded = ['id'];

    protected $casts = [
        'adjustment_date' => 'date',
        'total_value_centavos' => 'integer',
    ];

    /** @return HasMany<StockAdjustmentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class)->orderBy('line_no');
    }

    public function recalculateTotals(): void
    {
        $this->total_value_centavos = (int) $this->lines()->sum('value_centavos');
    }
}
