<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Location → location. Quantity only, no journal entry in v1 (08 §3): the
 * goods have not changed value or owner, only shelf.
 */
class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    protected $guarded = ['id'];

    protected $casts = ['transfer_date' => 'date'];

    /** @return HasMany<StockTransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('line_no');
    }
}
