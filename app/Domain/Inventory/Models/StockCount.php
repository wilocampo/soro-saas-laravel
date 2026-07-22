<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical count. `snapshot_qty` is frozen when counting starts, so the
 * variance measures the books against reality at one instant rather than
 * against a figure that moved while staff walked the aisles (08 §4.1).
 */
class StockCount extends Model
{
    protected $table = 'stock_counts';

    protected $guarded = ['id'];

    protected $casts = [
        'is_blind' => 'boolean',
        'counting_started_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
