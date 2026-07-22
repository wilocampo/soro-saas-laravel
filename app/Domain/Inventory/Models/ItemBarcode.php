<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Scan-to-add: a barcode resolves to an item AND the UoM it was scanned in. */
class ItemBarcode extends Model
{
    protected $table = 'item_barcodes';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
