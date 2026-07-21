<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class AtcRate extends Model
{
    protected $table = 'atc_rates';

    public $timestamps = false;

    protected $guarded = [];
}
