<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalPeriod extends Model
{
    protected $table = 'fiscal_periods';

    public $timestamps = false;

    protected $guarded = [];
}
