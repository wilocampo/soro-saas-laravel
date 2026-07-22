<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalogue entry. Only `inventory` items carry stock and post COGS; the
 * per-item GL bindings let two items capitalise to different accounts
 * (docs/specs/08 §1).
 */
class Item extends Model
{
    protected $table = 'items';

    protected $guarded = ['id'];

    protected $casts = [
        'purchase_to_stock_factor' => 'string',
        'reorder_point' => 'string',
        'avg_cost' => 'string',
        'last_cost' => 'string',
        'track_expiry' => 'boolean',
        'require_expiry' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function isStocked(): bool
    {
        return $this->item_type === 'inventory';
    }

    public function isLotTracked(): bool
    {
        return $this->tracking === 'lot';
    }

    /** @return HasMany<ItemBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class);
    }
}
