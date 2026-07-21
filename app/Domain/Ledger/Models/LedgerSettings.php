<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerSettings extends Model
{
    protected $table = 'ledger_settings';

    public $timestamps = false;

    protected $guarded = [];
}
