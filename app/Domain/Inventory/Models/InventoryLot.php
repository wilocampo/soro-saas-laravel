<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lot of stock with its own expiry. `remaining_qty` is deliberately NOT a
 * column: it is Σ `lot_movements.qty_delta`, read through
 * `LotLedger::remainingQty()` so it cannot drift (docs/specs/08 §1).
 */
class InventoryLot extends Model
{
    protected $table = 'inventory_lots';

    protected $guarded = ['id'];

    protected $casts = [
        'mfg_date' => 'date',
        'expiry_date' => 'date',
        'received_qty' => 'string',
        'cost_per_base' => 'string',
    ];

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function hasExpired(?string $asOf = null): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->toDateString() < ($asOf ?? now()->toDateString());
    }
}
