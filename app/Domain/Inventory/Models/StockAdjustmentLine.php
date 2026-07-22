<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentLine extends Model
{
    protected $table = 'stock_adjustment_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'qty_delta' => 'string',
        'unit_cost' => 'string',
        'value_centavos' => 'integer',
    ];

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
