<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    protected $table = 'tax_codes';

    public $timestamps = false;

    protected $guarded = [];
}
