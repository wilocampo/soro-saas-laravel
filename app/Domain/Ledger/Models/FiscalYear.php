<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $table = 'fiscal_years';

    public $timestamps = false;

    protected $guarded = [];
}
