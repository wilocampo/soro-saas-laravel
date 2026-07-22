<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received line. Both the entered quantity and the converted stock
 * quantity are kept, so a receipt reads back in the units it was typed in
 * (docs/specs/08 §5).
 */
class GoodsReceiptLine extends Model
{
    protected $table = 'goods_receipt_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'qty_entered' => 'string',
        'conversion_factor' => 'string',
        'qty_stock' => 'string',
        'unit_cost' => 'string',
        'line_cost_centavos' => 'integer',
        'expiry_date' => 'date',
    ];

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
