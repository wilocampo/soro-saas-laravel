<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $table = 'stock_count_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot_qty' => 'string',
        'counted_qty' => 'string',
        'variance_qty' => 'string',
        'unit_cost' => 'string',
        'variance_centavos' => 'integer',
    ];

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
