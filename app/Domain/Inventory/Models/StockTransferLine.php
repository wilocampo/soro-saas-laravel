<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    protected $table = 'stock_transfer_lines';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['qty' => 'string'];
}
